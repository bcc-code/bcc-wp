<?php

setcookie('wordpress_nocache', 'true');

$pubsub_event = file_get_contents('php://input');
if(empty($pubsub_event)) {
    wp_die("Missing request body");
}
$pubsub_event = json_decode($pubsub_event);

if(empty($pubsub_event)) {
    wp_die("Cannot decode event body");
}

if(!property_exists($pubsub_event, "data")) {
    wp_die("Invalid payload: missing data property");
}

$event_data = json_decode($pubsub_event->data);

if(empty($event_data)) {
    wp_die("Cannot decode event data");
}
if(!property_exists($event_data, "uid")) {
    wp_die("Invalid payload: missing uid property");
}

if (!empty($event_data->uid)) {    
    delete_transient( 'coreapi_user_groups_'.$event_data->uid );
}

?>
