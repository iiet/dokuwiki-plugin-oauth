<?php
/**
 * Default settings for the oauth plugin
 *
 * @author Andreas Gohr <andi@splitbrain.org>
 */

$conf['internal-key']      = '';
$conf['internal-secret']   = '';
$conf['internal-authurl']  = 'https://accounts.iiet.pl/oauth/authorize';
$conf['internal-tokenurl'] = 'https://accounts.iiet.pl/oauth/token';
$conf['internal-data-endpoint'] = 'https://accounts.iiet.pl/appapi/v1/students/me';
$conf['internal-api-aid'] = '';
$conf['internal-api-token'] = '';
$conf['internal-api-endpoint'] = 'https://accounts.iiet.pl/appapi/v1/students/';