<?php
declare(strict_types=1);

function mailHeaders(string $raw): array {
    $raw=preg_replace("/\r?\n[ \t]+/",' ',$raw);
    $headers=[];
    foreach(preg_split('/\r?\n/',$raw) as $line){
        if(!str_contains($line,':'))continue;
        [$key,$value]=explode(':',$line,2);
        $key=strtolower(trim($key));
        $headers[$key]=isset($headers[$key])?$headers[$key].', '.trim($value):trim($value);
    }
    return $headers;
}
function mailDecodeHeader(string $value): string {
    $decoded=iconv_mime_decode($value,ICONV_MIME_DECODE_CONTINUE_ON_ERROR,'UTF-8');
    return $decoded===false?$value:$decoded;
}
function mimeParameter(string $header,string $name): ?string {
    if(preg_match('/(?:^|;)\s*'.preg_quote($name,'/').'\s*=\s*(?:"([^"]*)"|([^;\s]+))/i',$header,$m))return $m[1]!==''?$m[1]:($m[2]??'');
    return null;
}
function mailHtmlText(string $html): string {
    $html=preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is','',$html);
    $html=preg_replace('~<(?:br\b[^>]*|/p|/div|/tr|/li)>~i',"\n",$html);
    return trim(html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8'));
}
function mailMime(string $raw,int $depth=0): array {
    if($depth>12)throw new RuntimeException('Слишком сложная структура письма.');
    $parts=preg_split("/\r?\n\r?\n/",$raw,2);
    $headers=mailHeaders($parts[0]);$body=$parts[1]??'';
    $type=$headers['content-type']??'text/plain; charset=UTF-8';
    $media=strtolower(trim(explode(';',$type)[0]));
    $disposition=$headers['content-disposition']??'';
    $filename=mimeParameter($disposition,'filename')??mimeParameter($type,'name');
    if(str_starts_with(strtolower($disposition),'attachment')||$filename!==null){
        return ['plain'=>'','html'=>'','attachments'=>[$filename?mailDecodeHeader($filename):'Вложение']];
    }
    if(str_starts_with($media,'multipart/')){
        $boundary=mimeParameter($type,'boundary');
        if(!$boundary)throw new RuntimeException('В письме отсутствует MIME boundary.');
        $chunks=preg_split('/(?:^|\r?\n)--'.preg_quote($boundary,'/').'(?:--)?[ \t]*(?:\r?\n|$)/',$body);
        $out=['plain'=>'','html'=>'','attachments'=>[]];
        foreach(array_slice($chunks,1,-1) as $chunk){
            if(trim($chunk)==='')continue;
            $part=mailMime($chunk,$depth+1);
            foreach(['plain','html'] as $kind)if($part[$kind]!=='')$out[$kind].=($out[$kind]!==''?"\n\n":'').$part[$kind];
            $out['attachments']=array_merge($out['attachments'],$part['attachments']);
        }
        return $out;
    }
    $encoding=strtolower($headers['content-transfer-encoding']??'');
    if($encoding==='base64'){$decoded=base64_decode(preg_replace('/\s/','',$body),true);if($decoded===false)throw new RuntimeException('Неверная кодировка письма.');$body=$decoded;}
    elseif($encoding==='quoted-printable')$body=quoted_printable_decode($body);
    $charset=mimeParameter($type,'charset')??'UTF-8';
    if(strcasecmp($charset,'UTF-8')!==0){$converted=iconv($charset,'UTF-8//IGNORE',$body);if($converted===false)throw new RuntimeException('Не удалось прочитать кодировку письма.');$body=$converted;}
    return ['plain'=>$media==='text/plain'?trim($body):'','html'=>$media==='text/html'?mailHtmlText($body):'',
        'attachments'=>$media==='text/plain'||$media==='text/html'?[]:['Вложение ('.$media.')']];
}
function mailMessage(string $raw): array {
    $parts=preg_split("/\r?\n\r?\n/",$raw,2);
    $h=mailHeaders($parts[0]);$mime=mailMime($raw);
    return ['subject'=>mailDecodeHeader($h['subject']??'(без темы)'),'from'=>mailDecodeHeader($h['from']??''),
        'date'=>$h['date']??'','message_id'=>$h['message-id']??'',
        'body'=>$mime['plain']!==''?$mime['plain']:$mime['html'],'attachments'=>$mime['attachments']];
}
