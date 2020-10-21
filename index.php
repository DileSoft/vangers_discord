<?php

require_once("config.php");
require_once("db.php");
$db = new db($config["db_host"], $config["db_user"], $config["db_pass"], $config["db_name"]);

if ($_REQUEST["password"] != $config["password"]) {
    api_error("wrong_password");
}

if ($_REQUEST["action"] == "getUser") {
    $user = get_user($_REQUEST["discord_id"]);
    api_answer($user);
}
if ($_REQUEST["action"] == "addBeebs") {
    $user = get_user($_REQUEST["discord_id"]);
    if (time() - strtotime($user["last_add"]) < 24*60*60) {
        api_error("Today payed");
    }
    $discord_id = $db->escape($_REQUEST["discord_id"]);
    $beebs = intval($_REQUEST["beebs"]);
    $db->sql("UPDATE user SET balance = balance + $beebs, last_add = NOW() WHERE discord_id = '{$discord_id}'");
    api_answer(true);
}
if ($_REQUEST["action"] == "removeBeebs") {
    $user = get_user($_REQUEST["discord_id"]);
    $discord_id = $db->escape($_REQUEST["discord_id"]);
    $beebs = intval($_REQUEST["beebs"]);
    if ($user["balance"] < $beebs) {
        api_error("No beebs");
    }
    $db->sql("UPDATE user SET balance = balance - $beebs WHERE discord_id = '{$discord_id}'");
    api_answer(true);
}

function get_user($discord_id) {
    global $db;
    $discord_id = $db->escape($discord_id);
    $user = $db->get_row("SELECT * FROM user WHERE discord_id = '{$discord_id}'");
    if (!$user) {
        $db->sql("INSERT INTO user(discord_id) VALUES('{$discord_id}')");
    }
    $user = $db->get_row("SELECT * FROM user WHERE discord_id = '{$discord_id}'");
    return $user;
}

function api_answer($data) {
    echo json_encode($data);
}

function api_error($data) {
    http_response_code( 400 );
    echo json_encode(["error"=> $data]);
    die();
}