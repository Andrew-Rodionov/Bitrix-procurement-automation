<?php
require dirname(__DIR__).'/src/config.php';
sessionInit();header('Cache-Control: no-store');
$connected=isset($_SESSION['mail_auth']);
$boot=['csrf'=>$_SESSION['csrf'],'connected'=>$connected,'address'=>$_SESSION['mail_auth']['address']??''];
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Закупки · помощник</title><link rel="stylesheet" href="app.css"></head><body><main>
<header class="app-header"><div class="brand">ЗАКУПКИ / ПОМОЩНИК</div><button id="shutdown" class="shutdown-button" type="button">Завершить работу</button></header>
<p id="shutdown-status" class="note" role="status" hidden></p><div id="shutdown-error" class="error" hidden role="alert"></div>
<h1>От заявки — к выбору поставщика</h1>
<p class="intro">Опишите закупку, проверьте письмо и выберите поставщиков. Помощник соберёт ответы и сравнит предложения.</p>
<section class="card"><h2><span class="step">1</span> Почта помощника</h2>
<form id="login-form" <?= $connected?'hidden':'' ?> class="login">
<label>Адрес ящика<input name="address" type="email" required autocomplete="username" placeholder="имя@citology.ru"></label>
<label>Пароль<input name="password" type="password" required autocomplete="current-password"></label>
<button type="submit">Войти в почту</button></form>
<div id="account" <?= $connected?'':'hidden' ?>><strong id="address"><?= htmlspecialchars($boot['address'],ENT_QUOTES,'UTF-8') ?></strong> <button id="logout" class="secondary" type="button">Выйти и отключить фоновую проверку</button></div>
<p class="note">После входа фоновый обработчик проверяет почту каждый час, пока запущен проект, даже при закрытой вкладке. Для этого данные входа сохраняются в зашифрованном виде на этом компьютере. Выход отключает подключение и удаляет сохранённый пароль. После 30 минут бездействия для работы с сайтом понадобится войти снова.</p>
<p id="worker-status" class="note" role="status"></p><div id="login-error" class="error" hidden role="alert"></div></section>
<div id="workspace" <?= $connected?'':'hidden' ?>><div class="grid">
<section class="card"><h2><span class="step">2</span> Что необходимо купить</h2>
<form id="compose-form"><label for="brief">Тезисы для ИИ</label>
<textarea id="brief" required maxlength="8000" placeholder="Нужен 1 УЗИ-аппарат с двумя датчиками и 3 монитора пациента. Поставка в Воронеж. Укажите важные характеристики, сроки, бюджет и предпочтения по оплате, если они известны."></textarea>
<button type="submit">Сформировать письмо</button><p class="note">ИИ подготовит запрос коммерческого предложения. Перед отправкой проверьте товары, количества и условия.</p></form><div id="compose-error" class="error" hidden role="alert"></div></section>
<section class="card"><h2><span class="step">3</span> Письмо и поставщики</h2>
<p id="draft-empty" class="muted">Сначала опишите закупку и сформируйте письмо.</p>
<form id="send-form" hidden><label>Тема<input id="subject" required maxlength="250"></label>
<label>Текст письма<textarea id="body" required maxlength="12000"></textarea></label>
<fieldset><legend>Выберите поставщиков</legend><div id="suppliers"></div></fieldset>
<p class="note">Список загружается из suppliers.txt. Каждому выбранному поставщику уходит отдельное письмо. В тему добавляется номер запроса для сбора ответов.</p>
<button type="submit">Отправить выбранным поставщикам</button></form><div id="send-status" role="status"></div><div id="send-error" class="error" hidden role="alert"></div></section></div>
<section class="card results"><div class="row"><h2><span class="step">4</span> Ответы и лучшие предложения</h2>
<div><button id="refresh" type="button">Обновить ответы и анализ</button><button id="retry" type="button" class="secondary" hidden>Повторить анализ с ошибками</button></div></div>
<p id="refresh-status" class="note" role="status">Ответов поставщиков ещё нет.</p><p id="check-time" class="note"></p>
<div id="refresh-error" class="error" hidden role="alert"></div>
<label>Запрос закупки<select id="campaign-select"><option value="">Запросов пока нет</option></select></label><div id="campaign-result" aria-live="polite"></div></section>
<details class="card"><summary>Отдельный анализ текста письма</summary>
<form id="manual-form"><label>Текст предложения<textarea id="manual-letter" required maxlength="12000"></textarea></label><button type="submit">Проанализировать текст</button></form>
<div id="manual-result"></div><div id="manual-error" class="error" hidden role="alert"></div></details></div>
<p class="note">Рекомендации ИИ предварительные. Перед выбором поставщика проверьте исходные письма и полноту условий. Анализируется текст письма; изображения пропускаются.</p></main>
<dialog id="original-dialog"><div class="row"><h2>Исходное письмо</h2><button id="close-dialog" class="secondary" type="button">Закрыть</button></div><div id="original-content"></div></dialog>
<script id="boot" type="application/json"><?= json_encode($boot,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?></script>
<script src="app.js" defer></script></body></html>
