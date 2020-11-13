<?php

class AmoApi{

    /*
     * @param $source - форма поступления в AMO
     * */
    public function __construct($source){

        $amo_login = get_option('aw-amo-login');
        $amo_hash = get_option('aw-amo-key');
        $amo_subdomain = get_option('aw-amo-subdomain');
        $amo_trade_site = get_option('aw-amo-site');

        $this->config = array(
            'USER_LOGIN' => $amo_login ? $amo_login : '',
            'USER_HASH' => $amo_hash ? $amo_hash : '',
            'SUBDOMAIN' => $amo_subdomain ? $amo_subdomain : '',
            'TRADE_SITE' => $amo_trade_site ? $amo_trade_site : '',
            'TARGET_IM' => !empty($source) ? $source : 'Форма'
        );
        $this->source_names = array('utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'referrer');
        $this->auth();
        $this->custom_fields = $this->accountFieldsIds();
    }

    /*
     * Получение названий и id форм поступления
     * */
    public function getAmoFormsOfIncome()
    {
        $link = 'https://' . $this->config['SUBDOMAIN'] . '.amocrm.ru/private/api/v2/json/accounts/current';
        $account = $this->request($link, array(), 'GET');
        $amoFormsOfIncome = array();
        foreach ($account['account']['custom_fields']['leads'] as $field) {

            if ($field['name'] == 'Форма поступления') {
                foreach ($field['enums'] as $k => $v) {
                    $amoFormsOfIncome[$k] = $v;
                }
            }

        }

        return $amoFormsOfIncome;
    }

    /*
     * Получение id и кастомных полей аккаунта Amo
     * */
    public function accountFieldsIds(){

        /*

        Получение id и кастомных полей аккаунта Amo

        */

        $ids = array();
        $link = 'https://' . $this->config['SUBDOMAIN'] . '.amocrm.ru/private/api/v2/json/accounts/current';
        $account = $this->request($link, array(), 'GET');

        /* кастомные поля контактов */
        foreach ($account['account']['custom_fields']['contacts'] as $field) {

            if ($field['code'] == 'PHONE') {
                $ids['phone'] = (int)$field['id'];
            }

            if ($field['name'] == 'Площадка') {
                $ids['trade_site'] = (int)$field['id'];

                foreach ($field['enums'] as $k => $v) {
                    if ($v == $this->config['TRADE_SITE'])
                        $ids['trade_site_enum'] = $k;
                }
            }

            if ($field['name'] == 'Форма поступления') {
                $ids['target'] = (int)$field['id'];
                foreach ($field['enums'] as $k => $v) {
                    if ($v == $this->config['TARGET_IM'])
                        $ids['target_enum'] = $k;
                }
            }

            // Кастомные поля контактов utm метки
            if (in_array($field['name'], $this->source_names)) {
                $ids[$field['name']] = (int)$field['id'];
            }

        }

        foreach ($account['account']['leads_statuses'] as $field) {
            if ($field['name'] == 'Заявка') {
                $ids['status_id'] = (int)$field['id'];
            }
        }

        /* кастомные поля сделок */
        foreach ($account['account']['custom_fields']['leads'] as $field) {

            if ($field['name'] == 'Площадка') {
                $ids['leadCustomFields']['trade_site'] = (int)$field['id'];

                foreach ($field['enums'] as $k => $v) {
                    if ($v == $this->config['TRADE_SITE'])
                        $ids['leadCustomFields']['trade_site_enum'] = $k;
                }
            }

            if ($field['name'] == 'Форма поступления') {
                $ids['leadCustomFields']['target'] = (int)$field['id'];
                foreach ($field['enums'] as $k => $v) {
                    if ($v == $this->config['TARGET_IM'])
                        $ids['leadCustomFields']['target_enum'] = $k;
                }
            }

            // Кастомные поля контактов utm метки
            if (in_array($field['name'], $this->source_names)) {
                $ids['leadCustomFields'][$field['name']] = (int)$field['id'];
            }

        }

        return $ids;

    }

    /*
     * обновление кастомных полей utm меток, источников перехода в контактах. используется если к скрипту обращается Roistat
     * */
    function updateAmoContactByPhone($caller){

        $source_array = array();

        $link = 'https://' . $this->config['SUBDOMAIN'] . '.amocrm.ru/private/api/v2/json/contacts/list?limit_rows=1&query='.$caller['caller'];
        $check = $this->request($link, array(), 'GET');

        if (count($check['contacts']) != 0) {

            foreach ($check['contacts'] as $contact) {
                $contact_id = $contact['id'];
            }

            $href = 'https://' . $this->config['SUBDOMAIN'] . '.amocrm.ru/private/api/v2/json/contacts/set';
            $accountFields = $this->custom_fields;


            $filledFields = 0; //переменная для подсчета количества заполненных полей

            foreach ($accountFields as $key => $custom_field){ //объединяем массивы (значения utm полей сделок с id utm полей контактов)
                if(in_array($key, $this->source_names)){

                    foreach($accountFields['leadCustomFields'] as $k => $v){

                        if($key == $k){

                            foreach($caller['custom_fields'] as $callerKey => $callerVal){

                                if($callerKey == $v){

                                    $source_array[$custom_field] = $callerVal;

                                    if($callerVal != '-')
                                        $filledFields++;

                                    /* данные о полях для создания одинаковых значений utm_source и referrer */
                                    if($key == 'utm_source') {
                                        $utm_source_value['utm_source'] = $callerVal;
                                        $utm_source_id_value[$custom_field] = $callerVal;
                                    }

                                    if($key == 'referrer') {
                                        $referrer_value['referrer'] = $callerVal;
                                        $referrer_id_value[$custom_field] = $callerVal;
                                    }

                                }

                            }

                        }

                    }

                }
            }

            foreach($utm_source_id_value as $key => $val){//если utm_source пустые - берем значение из referrer
                if($val == '-') {
                    foreach($referrer_value as $k => $v){
                        if($v != '-'){

                            $referrer_value = array('referrer' => $v); //данные о referrer для передачи в сделку
                            if (strpos($v, 'yandex') !== false) {
                                $v = 'seoyandex';
                            }
                            if (strpos($v, 'google') !== false) {
                                $v = 'seogoogle';
                            }
                            $source_array[$key] = $v;
                            $utm_source_value = array('utm_source' => $v); //данные о source для передачи в сделку

                        }
                    }
                }
            }

            if($filledFields == 0){ //если utm и referrer пустые - заполняем referrer и utm_source как прямой заход
                foreach($utm_source_id_value as $key => $val){
                    $source_array[$key] = 'directinput';
                    $utm_source_value = array('utm_source' => 'directinput'); //данные о source для передачи в сделку
                }
                foreach($referrer_id_value as $key => $val){
                    $source_array[$key] = 'directinput';
                    $referrer_value = array('referrer' => 'directinput'); //данные о referrer для передачи в сделку
                }
            }

            $updating_fields_array = array();

            foreach ($source_array as $k => $v) {
                $updating_fields_array[] = array(
                    'id' => $k,
                    'values' => array(
                        array(
                            'value' => $source_array[$k]
                        )
                    )
                );
            }

            $formIds = array();
            $link = 'https://' . $this->config['SUBDOMAIN'] . '.amocrm.ru/private/api/v2/json/accounts/current';
            $account = $this->request($link, array(), 'GET');

            foreach ($account['account']['custom_fields']['contacts'] as $field) {

                if ($field['name'] == 'Форма поступления') {
                    $formIds['target'] = (int)$field['id'];
                    foreach ($field['enums'] as $k => $v) {
                        if ($v == $this->config['TARGET_PHONE'])
                            $formIds['target_enum'] = $k;
                    }
                }

                if ($field['name'] == 'Площадка') {
                    $formIds['trade_site'] = (int)$field['id'];
                    foreach ($field['enums'] as $k => $v) {
                        if ($v == $this->config['TRADE_SITE'])
                            $formIds['trade_site_enum'] = $k;
                    }
                }

            }

            // Площадка
            $updating_fields_array[] = array(
                'id' => $formIds['trade_site'],
                'values' => array($formIds['trade_site_enum'])
            );

            // Форма поступления
            $updating_fields_array[] = array(
                'id' => $formIds['target'],
                'values' => array($formIds['target_enum'])
            );

            $contacts['request']['contacts']['update'] = array(
                array(
                    'id' => $contact_id,
                    'last_modified' => (new DateTime('NOW'))->getTimestamp(),
                    'custom_fields' => $updating_fields_array
                )
            );

            if($this->request($href, $contacts)){

                //запись лога
                $file = '';
                foreach ($formIds as $key => $value) {
                    $file .= $key.' => '.$value.PHP_EOL;
                }
                foreach ($source_array as $key => $value) {
                    $file .= $key.' => '.$value.PHP_EOL;
                }
                file_put_contents('webhook-log.txt', PHP_EOL.'Обновлены поля контакта:'.PHP_EOL.$file, FILE_APPEND);

            }
            else{
                file_put_contents('webhook-log.txt', PHP_EOL.'Поля контакта НЕ обновлены'.PHP_EOL, FILE_APPEND);
            }

            return array_merge($referrer_value, $utm_source_value); //данные о источнике перехода для передачи в сделку
        }
        else{
            file_put_contents('webhook-log.txt', PHP_EOL.'Нет контактов с номером из webhook'.PHP_EOL, FILE_APPEND);
            echo 'Нет контактов с таким номером';
        }

    }

    /*
     * обновление кастомных полей сделки - источники перехода (utm_source, referrer)
        используется при звонке Roistat
     * */
    function updateAmoLeadByPhone($source_values, $caller){

        $updateLog = array();

        $source_array = array();

        $customFieldsLeadsIds = $this->custom_fields;

        $link = 'https://' . $this->config['SUBDOMAIN'] . '.amocrm.ru/private/api/v2/json/leads/list?id='.$caller['order_id'];
        $lead = $this->request($link, array(), 'GET');

        foreach ($lead['leads'] as $lead_data) {
            $status_id = $lead_data['status_id'];
            $lead_name = $lead_data['name'];
        }

        $source_names = array('referrer', 'utm_source');

        foreach ($customFieldsLeadsIds['leadCustomFields'] as $k => $v) {

            if (in_array($k, $source_names)) {

                if($k == 'referrer'){

                    $updateLog[$v] = $source_values['referrer'];

                    $source_array[] = array(
                        'id' => $v,
                        'values' => array(
                            array(
                                'value' => $source_values['referrer']
                            )
                        )
                    );

                }

                if($k == 'utm_source'){

                    $updateLog[$v] = $source_values['utm_source'];

                    $source_array[] = array(
                        'id' => $v,
                        'values' => array(
                            array(
                                'value' => $source_values['utm_source']
                            )
                        )
                    );

                }

            }

        }

        $leads['request']['leads']['update'] = array(
            array(
                'id' => $caller['order_id'],
                'name' => $lead_name,
                'last_modified' => (new DateTime('NOW'))->getTimestamp(),
                'status_id' => $status_id,
                'custom_fields' => $source_array
            )
        );

        $link = 'https://' . $this->config['SUBDOMAIN'] . '.amocrm.ru/private/api/v2/json/leads/set';

        if($this->request($link, $leads)){

            $file = '';
            foreach ($updateLog as $key => $value) {
                $file .= $key.' => '.$value.PHP_EOL;
            }
            file_put_contents('webhook-log.txt', PHP_EOL.'Обновлены поля сделки:'.PHP_EOL.$file.PHP_EOL, FILE_APPEND);

        }
        else{
            file_put_contents('webhook-log.txt', PHP_EOL.'Поля сделки НЕ обновлены'.PHP_EOL, FILE_APPEND);
        }

    }

    /*
     * создание сделок при обращении с формы
     * */
    function makeLeadAmo($utm_array){

        /*

        создание сделок при обращении с формы

        */

        $utmLeadsIds = $this->custom_fields;

        //utm метки
        foreach ($utmLeadsIds['leadCustomFields'] as $k => $v) {
            if (in_array($k, $this->source_names)) {
                $utmLead_array[] = array(
                    'id' => $v,
                    'values' => array(
                        array(
                            'value' => $utm_array[$k]
                        )
                    )
                );
            }
            if (get_option('aw-roistat-active') == 'yes' && $k == 'roistat') {
                $roistat_cookie = isset($_COOKIE['roistat_visit']) ? $_COOKIE['roistat_visit'] : null;
                $utmLead_array[] = array(
                    'id' => $v,
                    'values' => array(
                        array(
                            'value' => $roistat_cookie
                        )
                    )
                );
            }
        }

        // Площадка
        $utmLead_array[] = array(
            'id' => $utmLeadsIds['leadCustomFields']['trade_site'],
            'values' => array($utmLeadsIds['leadCustomFields']['trade_site_enum'])
        );

        // Форма поступления
        $utmLead_array[] = array(
            'id' => $utmLeadsIds['leadCustomFields']['target'],
            'values' => array($utmLeadsIds['leadCustomFields']['target_enum'])
        );

        $leadTags = array();

        foreach($utm_array as $key => $val){
            if($val != '-' && $key != 'referrer'){
                $leadTags[] = $val;
            }
        }

        $leadTags = implode(', ', $leadTags);

        $leads['add'] = array(
            array(
                'name' => 'Неразобранное',
                'status_id' => $utmLeadsIds['status_id'],
                'tags' => $leadTags,
                'custom_fields' => $utmLead_array
            )
        );

        $link = 'https://' . $this->config['SUBDOMAIN'] . '.amocrm.ru/api/v2/leads';
        $params = $leads;

        $lead = $this->request($link, $params);

        /* возвращаем id лида для привязки к контакту */

        $id = '';

        foreach ($lead['leads']['add'] as $v) {
            if (is_array($v))
                $id = $v['id'];
        }

        return $id;
    }

    /*
     * создание контактов при обращении с формы
     * */
    function makeContactAmo($id, $utm_array, $form_data){

        /*

        создание контактов при обращении с формы

        */

        $contact_id = null;
        $linked_leads = array($id);
        $newfields_array = array();

        $link = 'https://' . $this->config['SUBDOMAIN'] . '.amocrm.ru/api/v2/contacts?limit_rows=1&query=' . $form_data['phone'];
        $check = $this->request($link, array(), 'GET');

        if (count($check['contacts']) != 0) {
            foreach ($check['contacts'] as $contact) {
                $contact_id = $contact['id'];
                $linked_leads = array_merge($linked_leads, $contact['linked_leads_id']);
            }
        }

        $link = 'https://' . $this->config['SUBDOMAIN'] . '.amocrm.ru/api/v2/contacts';

        // Заполнение utm катомных полей
        foreach ($this->custom_fields as $k => $v) {
            if (in_array($k, $this->source_names)) {
                $newfields_array[] = array(
                    'id' => $v,
                    'values' => array(
                        array(
                            'value' => $utm_array[$k]
                        )
                    )
                );
            }
        }

        if ($contact_id != null) {
            $contacts['update'] = array(
                array(
                    'id' => $contact_id,
                    'updated_at' => (new DateTime('NOW'))->getTimestamp(),
                    'leads_id' => $linked_leads,
                    'custom_fields' => $newfields_array
                )
            );
        } else {
            $newfields_array[] = array(
                'id' => $this->custom_fields['phone'],
                'values' => array(
                    array(
                        'value' => $form_data['phone'],
                        'enum' => 'MOB'
                    )
                )
            );

            // Площадка
            $newfields_array[] = array(
                'id' => $this->custom_fields['trade_site'],
                'values' => array($this->custom_fields['trade_site_enum'])
            );

            // Форма поступления
            $newfields_array[] = array(
                'id' => $this->custom_fields['target'],
                'values' => array($this->custom_fields['target_enum'])
            );

            $contacts['add'] = array(
                array(
                    'leads_id' => $linked_leads,
                    'name' => isset($form_data['fio']) && $form_data['fio'] != '' ? $form_data['fio'] : 'Имя не указано',
                    'custom_fields' => $newfields_array
                )
            );
        }

        $this->request($link, $contacts);

    }

    /*
     * создание записи в примечании о заказанных товарах
     * */
    function addNoteAmo($note_text, $lead_id){

        $link = 'https://' . $this->config['SUBDOMAIN'] . '.amocrm.ru/private/api/v2/json/notes/set';

        $note['request']['notes']['add'] = array(
            array(
                'element_id'    => $lead_id,
                'element_type'  => '2', // lead
                'note_type'     => '4', // common - обычная заметка
                'text'          => $note_text,
                'created_at'    => time()
            )
        );

        return $this->request($link, $note);

    }

    /*
     * запрос curl к amo
     * */
    function request($link, $data = array(), $method = 'POST'){

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_URL, $link);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_COOKIEFILE, $_SERVER['DOCUMENT_ROOT'] . '/leadform/cookie.txt');
        curl_setopt($curl, CURLOPT_COOKIEJAR, $_SERVER['DOCUMENT_ROOT'] . '/leadform/cookie.txt');
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);

        $out = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $response = json_decode($out, true);
        $response = $response['response'];

        if ($code != 200 && $code != 204) {
            if (isset($response['error'])) die($response['error']);
        }

        return $response;

    }

    /*
     * авторизация в аккаунте amo
     * */
    function auth(){

        $link = 'https://' . $this->config['SUBDOMAIN'] . '.amocrm.ru/private/api/auth.php?type=json';
        $data = array(
            'USER_LOGIN' => $this->config['USER_LOGIN'],
            'USER_HASH' => $this->config['USER_HASH']
        );

        $response = $this->request($link, $data);

        if (!isset($response['auth'])) {
            echo json_encode(array('error' => 'Ошибка на стороне сервера! Системный администратор был уведомлен! Повторите попытку позже!'));
            mail($this->config['EMAIL_TO'], 'Ошибка!', 'Ошибка авторизации в системе amoCRM, проверьте данные конфигурации скрипта lead.php!');
            die;
        }

    }

}
