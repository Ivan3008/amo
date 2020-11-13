function awAddAmoLead($order_id, $order = null)
{
    if ('yes' != get_option('aw-amo-active'))
        return;

    $order = !$order ? wc_get_order( $order_id ) : $order;
    $order_data = $order->get_data();
    $name = $order_data['billing']['first_name'].' '.$order_data['billing']['last_name'];
    $phone = $order_data['billing']['phone'];
    $email = $order_data['billing']['email'];
    $message = $order->get_customer_note();

    $utm_array = unserialize(wp_unslash($_COOKIE['referral_source']));
    $contact_data = array(
        'fio'           => $name ? $name : 'Имя не указано',
        'phone'         => $phone ? $phone : '',
        'email'         => $email ? $email : '',
        'message'       => $message ? $message : 'Нет',
    );

    $amo_source = ($aw_amo_woocommerce_source = get_option('aw-amo-woocommerce-source')) ? $aw_amo_woocommerce_source : 'Заказ из интернет магазина';
    $amo = new AmoApi($amo_source);
    $relatedLeadId = $amo->makeLeadAmo($utm_array);
    $amo->makeContactAmo($relatedLeadId, $utm_array, $contact_data);

    $products_text = '';
    $order_items = $order->get_items();
    $order_items_count = count($order_items);
    $y = 0;
    if (!empty($order_items)) {
        foreach ($order_items as $item_data) {
            $y++;
            $products_text .= $item_data->get_name(). '('.$item_data->get_product()->get_price().'р.)';
            if ($order_items_count != $y)
                $products_text .= ', ';
        }
    }

     if (!empty($message))
         $amo->addNoteAmo('Примечание от клиента: '.$message, $relatedLeadId);

     if (!empty($products_text))
         $amo->addNoteAmo('Заказанные товар(ы): '.$products_text, $relatedLeadId);
}
