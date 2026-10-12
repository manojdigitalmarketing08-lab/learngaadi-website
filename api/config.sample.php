<?php
/**
 * Copy this file to config.php on the server and fill in your Hostinger MySQL details
 * (hPanel > Databases > MySQL Databases). Never commit config.php.
 */
return [
    'db_host' => 'localhost',
    'db_name' => 'u123456789_learngaadi',
    'db_user' => 'u123456789_leads',
    'db_pass' => 'CHANGE_ME',

    // ---- WhatsApp Cloud API (Meta): every new lead is sent to the numbers below ----
    // developers.facebook.com > your app > WhatsApp > API Setup
    'wa_phone_number_id' => 'CHANGE_ME',   // Phone number ID of the business sender number
    'wa_access_token'    => 'CHANGE_ME',   // Permanent System User token (not the 24h test token)
    'wa_api_version'     => 'v21.0',

    // Who receives the lead alerts: country code + number, digits only. Add as many as you like.
    'wa_notify_numbers'  => ['918296161555'],

    // Approved message template (needed to message the owner when they have not chatted in the last 24h).
    // Leave '' to send plain text instead (only delivered inside the 24-hour window).
    // Create it in WhatsApp Manager > Message templates, category Utility, English, with 6 body variables:
    //   New website lead {{6}}
    //   Name: {{1}}
    //   Phone: {{2}}
    //   Service: {{3}}
    //   Page: {{4}}
    //   Source: {{5}}
    'wa_template'        => 'new_lead',
    'wa_template_lang'   => 'en',

    // ---- Google Sheet (Apps Script web app, see api/google-sheet.gs) ----
    'sheet_webhook_url' => 'CHANGE_ME',    // Web app URL ending in /exec
    'sheet_secret'      => 'CHANGE_ME',    // Same value as SECRET in google-sheet.gs

    // ---- Email alerts via Hostinger SMTP (hPanel > Emails > create a mailbox) ----
    'smtp_host' => 'smtp.hostinger.com',
    'smtp_port' => 465,                    // 465 = SSL, 587 = STARTTLS
    'smtp_user' => 'leads@learngaadi.in',  // full mailbox address
    'smtp_pass' => 'CHANGE_ME',
    'mail_from' => 'leads@learngaadi.in',  // must be the mailbox above
    'mail_to'   => ['gmail.learngaadii@gmail.com'],
];
