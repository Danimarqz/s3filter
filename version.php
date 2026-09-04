<?php
defined('MOODLE_INTERNAL') || die();

// Subir esto NO es burocracia: player::asset_url() lo usa como ?v= de
// watermark.js y del resto de assets. Con el bundle del watermark cambiado y la
// versión igual, el webview de la app sigue sirviendo el JS viejo de su caché.
$plugin->version   = 2026090400;   // YYYYMMDDXX
$plugin->requires  = 2022041900;   // Moodle 4.0+
$plugin->component = 'filter_impronta';
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '0.2';
$plugin->settings = true;
