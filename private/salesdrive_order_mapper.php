<?php

function sd_blank_to_null($value) {
    if ($value === '') return null;
    return $value;
}

function sd_int_or_null($value) {
    $value = sd_blank_to_null($value);
    return $value === null ? null : (int)$value;
}

function sd_float_or_zero($value) {
    $value = sd_blank_to_null($value);
    return $value === null ? 0 : (float)$value;
}

function sd_string_or_null($value) {
    $value = sd_blank_to_null($value);
    return $value === null ? null : (string)$value;
}

function sd_date_or_null($value) {
    $value = sd_blank_to_null($value);
    if ($value === null) return null;
    $ts = strtotime((string)$value);
    return $ts ? date('Y-m-d', $ts) : null;
}

function sd_datetime_or_null($value) {
    $value = sd_blank_to_null($value);
    if ($value === null) return null;
    $ts = strtotime((string)$value);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

function sd_first_array_value($arr, $keys) {
    if (!is_array($arr)) return null;
    foreach ($keys as $key) {
        if (array_key_exists($key, $arr) && $arr[$key] !== '') {
            return $arr[$key];
        }
    }
    return null;
}

function sd_first_delivery($ord) {
    if (!empty($ord['ord_delivery_data']) && is_array($ord['ord_delivery_data'])) {
        foreach ($ord['ord_delivery_data'] as $row) {
            if (is_array($row)) return $row;
        }
    }

    foreach (['ord_novaposhta', 'ord_ukrposhta', 'ord_meest', 'ord_justin', 'ord_rozetka_delivery', 'ord_delivery'] as $key) {
        if (!empty($ord[$key]) && is_array($ord[$key])) {
            $row = $ord[$key];
            if (!isset($row['provider'])) $row['provider'] = $key;
            return $row;
        }
    }

    return [];
}

function sd_first_contact($ord) {
    if (!empty($ord['contacts'][0]) && is_array($ord['contacts'][0])) {
        return $ord['contacts'][0];
    }
    if (!empty($ord['primaryContact']) && is_array($ord['primaryContact'])) {
        return $ord['primaryContact'];
    }
    return [];
}

function sd_phone($contact) {
    $phone = $contact['phone'] ?? null;
    if (is_array($phone)) return $phone[0] ?? null;
    return $phone;
}

function sd_email($contact) {
    $email = $contact['email'] ?? null;
    if (is_array($email)) return $email[0] ?? null;
    return $email;
}

function sd_json_value($value) {
    return json_encode($value, JSON_UNESCAPED_UNICODE);
}

function salesdrive_known_top_level_keys() {
    return [
        'id',
        'formId',
        'version',
        'ord_delivery_data',
        'primaryContact',
        'contacts',
        'zamovlenna',
        'products',
        'orderChecks',
        'shipping_method',
        'payment_method',
        'adresaDostavki',
        'comment',
        'timeEntryOrder',
        'holderTime',
        'organizationId',
        'postacalnik',
        'orderTime',
        'updateAt',
        'statusId',
        'paymentDate',
        'userId',
        'rejectionReason',
        'paymentAmount',
        'commissionAmount',
        'costPriceAmount',
        'shipping_costs',
        'expensesAmount',
        'profitAmount',
        'typeId',
        'payedAmount',
        'restPay',
        'document_ord_check',
        'call',
        'discountAmount',
        'sumaBorgu',
        'vzaemozalik',
        'sajt',
        'utmPage',
        'utmMedium',
        'campaignId',
        'utmSourceFull',
        'utmSource',
        'utmCampaign',
        'utmContent',
        'utmTerm',
        'externalId',
        'token',
        'promokod',
        'terminiVidpravlenna',
        'orderStock',
        'stockId',
        'integrationType',
        'integrationId',
        'integrationExternalTypeId',
        'ord_novaposhta',
        'ord_ukrposhta',
        'ord_meest',
        'ord_justin',
        'ord_rozetka_delivery',
        'ord_delivery',
    ];
}

function salesdrive_known_delivery_keys() {
    return [
        'senderId',
        'cityName',
        'provider',
        'type',
        'parentTrackingNumber',
        'trackingNumber',
        'statusCode',
        'deliveryDateAndTime',
        'idEntity',
        'areaName',
        'regionName',
        'cityType',
        'payer',
        'postpayPayer',
        'hasPostpay',
        'postpaySum',
        'trackingNumberRef',
        'cityRef',
        'settlementRef',
        'branchRef',
        'branchNumber',
        'address',
        'paymentMethod',
        'cargoType',
        'branch',
        'city',
        'street',
        'house',
        'flat',
        'EN',
        'barcode',
        'settlementName',
        'districtName',
        'region',
        'area',
        'branchName',
        'addressBranch',
        'addedToRegister',
        'manual',
        'legal',
        'liftToFloor',
        'floor',
        'elevator',
        'allowRedirectReturn',
        'additionalService',
        'isRedirect',
        'ageIdentification',
        'delivery',
        'fulfilmentTotalQty',
        'fulfilmentTotalSum',
        'fulfilmentIndividualPackaging',
        'deliveryLargeHouseholdAppliances',
        'isPrinted',
        'isScanSheetRef',
        'backDelivery',
        'cityTemplateName',
        'ownershipFormId',
        'ENref',
        'packing',
        'warehouseTypeId',
        'cost',
        'status',
        'dateStatusUpdate',
        'cityXlsName',
        'receiveDateTime',
        'streetName',
        'addressComment',
        'note',
        'companyName',
        'egroup',
        'egrpou',
        'parentTtnNumber',
        'shipping_costs',
    ];
}

function salesdrive_known_product_keys() {
    return [
        'formId',
        'productId',
        'parameter',
        'name',
        'nameTranslate',
        'documentName',
        'text',
        'sku',
        'barcode',
        'amount',
        'price',
        'costPrice',
        'discount',
        'percentDiscount',
        'commission',
        'percentCommission',
        'description',
        'stockId',
        'mass',
        'volume',
        'length',
        'width',
        'height',
        'restCount',
        'manufacturer',
        'keywords',
        'preSale',
        'upsell',
        'isComplect',
        'active',
        'complect',
        'photo',
        'priceTypes',
        'defaultPriceData',
        'costPriceCurrencyId',
        'categoryId',
        'categoryName',
        'href',
        'note',
        'uktzed',
        'exciseBarcodes',
    ];
}

function salesdrive_known_contact_keys() {
    return [
        'id',
        'formId',
        'version',
        'active',
        'createTime',
        'fName',
        'lName',
        'mName',
        'phone',
        'email',
        'company',
        'comment',
        'userId',
        'counterpartyId',
        'leadsCount',
        'leadsSalesCount',
        'leadsSalesAmount',
        'telegram',
        'instagramNick',
        'clientRating',
        'dateOfBirth',
        'yearOfBirth',
        'isPhoneHide',
        'isEmailHide',
        'counterparty',
    ];
}

function salesdrive_order_columns() {
    return array_keys(salesdrive_map_order([]));
}

function salesdrive_map_order(array $ord) {
    $contact = sd_first_contact($ord);
    $delivery = sd_first_delivery($ord);

    $np = $ord['ord_novaposhta'] ?? [];
    $up = $ord['ord_ukrposhta'] ?? [];

    $trackingNumber = sd_first_array_value($delivery, ['trackingNumber', 'EN', 'barcode']);
    if ($trackingNumber === null && is_array($np)) $trackingNumber = $np['EN'] ?? null;
    if ($trackingNumber === null && is_array($up)) $trackingNumber = $up['barcode'] ?? null;

    $cityRef = sd_first_array_value($delivery, ['cityRef', 'city']);
    if ($cityRef === null && is_array($np)) $cityRef = $np['city'] ?? null;

    $branchRef = sd_first_array_value($delivery, ['branchRef', 'branch']);
    if ($branchRef === null && is_array($np)) $branchRef = $np['branch'] ?? null;

    $idEntity = sd_first_array_value($delivery, ['idEntity', 'senderId']);
    if ($idEntity === null && is_array($np)) $idEntity = $np['idEntity'] ?? null;

    return [
        'id' => sd_int_or_null($ord['id'] ?? null),
        'form_id' => sd_int_or_null($ord['formId'] ?? null),
        'version' => sd_int_or_null($ord['version'] ?? null),
        'order_number' => sd_string_or_null($ord['zamovlenna'] ?? null),
        'order_time' => sd_datetime_or_null($ord['orderTime'] ?? null),
        'update_at' => sd_datetime_or_null($ord['updateAt'] ?? null),
        'payment_date' => sd_date_or_null($ord['paymentDate'] ?? null),
        'order_type_id' => sd_int_or_null($ord['typeId'] ?? null),
        'status_id' => sd_int_or_null($ord['statusId'] ?? null),
        'manager_id' => sd_int_or_null($ord['userId'] ?? null),
        'amount' => sd_float_or_zero($ord['paymentAmount'] ?? 0),
        'expenses_amount' => sd_float_or_zero($ord['expensesAmount'] ?? 0),
        'profit_amount' => sd_float_or_zero($ord['profitAmount'] ?? 0),
        'cost_price_amount' => sd_float_or_zero($ord['costPriceAmount'] ?? 0),
        'payed_amount' => sd_float_or_zero($ord['payedAmount'] ?? 0),
        'rest_pay' => sd_float_or_zero($ord['restPay'] ?? 0),
        'debt_amount' => sd_float_or_zero($ord['sumaBorgu'] ?? 0),
        'discount_amount' => sd_float_or_zero($ord['discountAmount'] ?? 0),
        'commission_amount' => sd_float_or_zero($ord['commissionAmount'] ?? 0),
        'shipping_costs' => sd_float_or_zero($ord['shipping_costs'] ?? 0),
        'client_first_name' => sd_string_or_null($contact['fName'] ?? null),
        'client_last_name' => sd_string_or_null($contact['lName'] ?? null),
        'client_phone' => sd_string_or_null(sd_phone($contact)),
        'client_email' => sd_string_or_null(sd_email($contact)),
        'client_comment' => sd_string_or_null($contact['comment'] ?? null),
        'shipping_method_id' => sd_int_or_null($ord['shipping_method'] ?? null),
        'payment_method_id' => sd_int_or_null($ord['payment_method'] ?? null),
        'shipping_address' => sd_string_or_null($ord['adresaDostavki'] ?? null),
        'site_id' => sd_int_or_null($ord['sajt'] ?? null),
        'supplier_id' => sd_int_or_null($ord['postacalnik'] ?? null),
        'organization_id' => sd_int_or_null($ord['organizationId'] ?? null),
        'campaign_id' => sd_int_or_null($ord['campaignId'] ?? null),
        'rejection_reason_id' => sd_int_or_null($ord['rejectionReason'] ?? null),
        'stock_id' => sd_int_or_null($ord['orderStock'] ?? ($ord['stockId'] ?? null)),
        'mutual_settlement_id' => sd_int_or_null($ord['vzaemozalik'] ?? null),
        'promo_code_id' => sd_int_or_null($ord['promokod'] ?? null),
        'dispatch_term_id' => sd_int_or_null($ord['terminiVidpravlenna'] ?? null),
        'np_ttn' => sd_string_or_null($np['EN'] ?? $trackingNumber),
        'np_city_ref' => sd_string_or_null($np['city'] ?? $cityRef),
        'np_warehouse_ref' => sd_string_or_null($np['branch'] ?? $branchRef),
        'np_id_entity' => sd_int_or_null($np['idEntity'] ?? $idEntity),
        'up_barcode' => sd_string_or_null($up['barcode'] ?? null),
        'utm_source' => sd_string_or_null($ord['utmSource'] ?? null),
        'utm_medium' => sd_string_or_null($ord['utmMedium'] ?? null),
        'utm_campaign' => sd_string_or_null($ord['utmCampaign'] ?? null),
        'utm_page' => sd_string_or_null($ord['utmPage'] ?? null),
        'utm_source_full' => sd_string_or_null($ord['utmSourceFull'] ?? null),
        'utm_content' => sd_string_or_null($ord['utmContent'] ?? null),
        'utm_term' => sd_string_or_null($ord['utmTerm'] ?? null),
        'external_id' => sd_string_or_null($ord['externalId'] ?? null),
        'delivery_provider' => sd_string_or_null($delivery['provider'] ?? null),
        'delivery_tracking_number' => sd_string_or_null($trackingNumber),
        'delivery_status_code' => sd_int_or_null($delivery['statusCode'] ?? null),
        'delivery_delivered_at' => sd_datetime_or_null($delivery['deliveryDateAndTime'] ?? null),
        'delivery_sender_id' => sd_int_or_null($delivery['senderId'] ?? null),
        'delivery_area_name' => sd_string_or_null(sd_first_array_value($delivery, ['areaName', 'area', 'regionName'])),
        'delivery_region_name' => sd_string_or_null(sd_first_array_value($delivery, ['regionName', 'districtName', 'region'])),
        'delivery_city_name' => sd_string_or_null(sd_first_array_value($delivery, ['cityName', 'city', 'settlementName'])),
        'delivery_city_ref' => sd_string_or_null($cityRef),
        'delivery_settlement_ref' => sd_string_or_null($delivery['settlementRef'] ?? null),
        'delivery_branch_ref' => sd_string_or_null($branchRef),
        'delivery_branch_number' => sd_int_or_null($delivery['branchNumber'] ?? null),
        'delivery_address' => sd_string_or_null(sd_first_array_value($delivery, ['address', 'branchName', 'addressBranch'])),
        'delivery_payer' => sd_string_or_null($delivery['payer'] ?? null),
        'delivery_has_postpay' => sd_int_or_null($delivery['hasPostpay'] ?? null),
        'delivery_postpay_sum' => sd_float_or_zero($delivery['postpaySum'] ?? 0),
        'delivery_payment_method' => sd_string_or_null($delivery['paymentMethod'] ?? null),
        'delivery_cargo_type' => sd_string_or_null($delivery['cargoType'] ?? null),
        'manager_comment' => sd_string_or_null($ord['comment'] ?? null),
        'full_json' => sd_json_value($ord),
    ];
}

function salesdrive_build_upsert_sql($table, array $columns, array $skipUpdate = ['id']) {
    $quoted = array_map(fn($c) => "`$c`", $columns);
    $params = array_map(fn($c) => ":$c", $columns);
    $updates = [];
    foreach ($columns as $column) {
        if (in_array($column, $skipUpdate, true)) continue;
        $updates[] = "`$column` = VALUES(`$column`)";
    }

    return "INSERT INTO `$table` (" . implode(', ', $quoted) . ") VALUES (" . implode(', ', $params) . ") " .
        "ON DUPLICATE KEY UPDATE " . implode(', ', $updates);
}

function salesdrive_bind_params(array $mapped) {
    $params = [];
    foreach ($mapped as $key => $value) {
        $params[":$key"] = $value;
    }
    return $params;
}
