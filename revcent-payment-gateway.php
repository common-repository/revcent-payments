<?php
/*
Plugin Name: RevCent Payments
Plugin URI: https://revcent.com/
Description: RevCent payment integration plugin for WordPress and WooCommerce.
Version: 1.6.3
*/

add_action("plugins_loaded", "revcent_payments_init", 0);
add_action("wp_enqueue_scripts", "revcent_track_js");
function revcent_payments_init()
{
    if (!class_exists("WC_Payment_Gateway")) {
        return;
    }
    include_once "revcent-payments.php";
    include_once "woofunnels/upstroke-woocommerce-one-click-upsell-revcent.php";
    add_filter("woocommerce_payment_gateways", "revcent_payments_gateway");
    function revcent_payments_gateway($methods)
    {
        $methods[] = "revcent_payments";
        return $methods;
    }
}
add_filter(
    "plugin_action_links_" . plugin_basename(__FILE__),
    "revcent_payments_action_links"
);
function revcent_payments_action_links($links)
{
    $plugin_links = [
        '<a href="' .
        admin_url("admin.php?page=wc-settings&tab=checkout") .
        '">' .
        __("Settings", "revcent-payments") .
        "</a>",
    ];
    return array_merge($plugin_links, $links);
}
function revcent_track_js()
{
    try {
        $options = get_option("woocommerce_revcent_payments_settings");
        if (
            $options !== false &&
            isset($options["revcent_dns_tracking"]) &&
            $options["revcent_dns_tracking"] === "yes"
        ) {
            wp_register_script(
                "revcent_track_js",
                "https://rctrk." . $_SERVER["HTTP_HOST"] . "/trk.js",
                null,
                "1.0",
                true
            );
            wp_enqueue_script("revcent_track_js");
        }
    } catch (Exception $ex) {
        //
    }
}

?>
