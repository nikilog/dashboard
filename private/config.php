<?php

return [
    'db_host' => '127.0.0.1',
    'db_name' => 'salesdrive',
    'db_user' => 'root',
    'db_pass' => 'Tvsc8585',

    'crm_api_base' => 'https://gusar.salesdrive.me/api/order/list/',
    'crm_api_key'  => trim(file_get_contents(__DIR__ . '/APIKEY.txt')),

    'success_statuses' => array (
  0 => 5,
  1 => 11,
  2 => 18,
  3 => 19,
),
    'hold_statuses'    => array (
  0 => 2,
  1 => 3,
  2 => 4,
  3 => 13,
  4 => 14,
),

    'reject_statuses'  => array (
  0 => 6,
  1 => 7,
  2 => 16,
  3 => 17,
),
    'excluded_statuses' => array (
  0 => 8,
),

    'managers_w' => array (
  0 => 5,
  1 => 6,
  2 => 9,
  3 => 12,
  4 => 14,
),
    'managers_b' => array (
  0 => 1,
  1 => 3,
  2 => 4,
  3 => 7,
  4 => 8,
),
];
