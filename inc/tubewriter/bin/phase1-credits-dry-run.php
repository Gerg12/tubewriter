<?php
error_reporting(E_ALL);
ini_set("display_errors", "1");
define("DB_HOST", "127.0.0.1:10004");
require dirname(__DIR__, 6) . "/wp-load.php";

if (!class_exists("TW_Credits")) { fwrite(STDERR, "TW_Credits missing\n"); exit(1); }

$pass = 0; $fail = 0;
function tw_check($name, $ok, $detail = "") {
	global $pass, $fail;
	if ($ok) { ++$pass; echo "PASS  {$name}" . ($detail !== "" ? " - {$detail}" : "") . "\n"; }
	else { ++$fail; echo "FAIL  {$name}" . ($detail !== "" ? " - {$detail}" : "") . "\n"; }
}

echo "=== UploadWriter Phase 1 dry-run tests ===\n";
echo "Pacific day: " . TW_Credits::pacific_day() . "\n";
$project_before = TW_Credits::project_used();
echo "Project used (before): {$project_before}\n\n";

$login = "uw_test_free_" . wp_generate_password(6, false, false);
$uid = wp_create_user($login, wp_generate_password(16, true, true), $login . "@example.test");
if (is_wp_error($uid)) {
	tw_check("1 create free user", false, $uid->get_error_message());
} else {
	$user = new WP_User($uid);
	$user->set_role("tubewriter");
	update_user_meta($uid, TW_Credits::META_TEST_ACCOUNT, "1");
	tw_user_set_beta_pending($uid, false);
	TW_Credits::set_plan($uid, "free", true);
	TW_Credits::set_mode($uid, "byok");
	TW_Context::set_user($uid);
	TW_Store::set("gemini_key", "test-key-not-real-dry-run-only");

	$left = TW_Credits::writing_left($uid);
	tw_check("1a free starts with 3 writing", 3 === $left, "left={$left}");
	tw_check("1b free daily cap 100", 100 === TW_Credits::daily_cap($uid), "cap=" . TW_Credits::daily_cap($uid));
	tw_check("1c free thumb 0", 0 === TW_Credits::thumb_left($uid) && 0 === TW_Credits::thumb_allowance($uid));
	TW_Credits::spend_writing($uid);
	tw_check("1e after first generate left=2", 2 === TW_Credits::writing_left($uid), "left=" . TW_Credits::writing_left($uid));
	TW_Credits::spend_writing($uid);
	tw_check("1f after second generate left=1", 1 === TW_Credits::writing_left($uid), "left=" . TW_Credits::writing_left($uid));
	tw_check("1g view draft no spend", 1 === TW_Credits::writing_left($uid));
}

if (!empty($uid) && !is_wp_error($uid)) {
	$before = TW_Credits::thumb_left($uid);
	$block = TW_Credits::assert_thumb_credit("standard", $uid);
	tw_check("2a free thumb blocked", is_wp_error($block) && false !== stripos($block->get_error_message(), "No thumbnail credits on this plan"), is_wp_error($block) ? $block->get_error_message() : "not blocked");
	tw_check("2b no thumb credit spent", $before === TW_Credits::thumb_left($uid));
}

$slogin = "uw_test_studio_" . wp_generate_password(6, false, false);
$sid = wp_create_user($slogin, wp_generate_password(16, true, true), $slogin . "@example.test");
if (is_wp_error($sid)) {
	tw_check("3 create studio user", false, $sid->get_error_message());
} else {
	$suser = new WP_User($sid);
	$suser->set_role("tubewriter");
	update_user_meta($sid, TW_Credits::META_TEST_ACCOUNT, "1");
	tw_user_set_beta_pending($sid, false);
	TW_Credits::set_plan($sid, "studio", true);
	TW_Credits::set_mode($sid, "byok");
	TW_Context::set_user($sid);
	TW_Store::set("gemini_key", "test-key-not-real-dry-run-only");
	tw_check("3a studio writing 100", 100 === TW_Credits::writing_left($sid));
	tw_check("3b studio thumb 10", 10 === TW_Credits::thumb_left($sid));
	tw_check("3c studio daily 200", 200 === TW_Credits::daily_cap($sid));
	TW_Credits::spend_thumb("standard", $sid);
	tw_check("3e after standard left=9", 9 === TW_Credits::thumb_left($sid), "left=" . TW_Credits::thumb_left($sid));
	TW_Credits::spend_thumb("high", $sid);
	tw_check("3g after high left=6", 6 === TW_Credits::thumb_left($sid), "left=" . TW_Credits::thumb_left($sid));
	$before_fail = TW_Credits::thumb_left($sid);
	TW_Credits::assert_thumb_credit("standard", $sid);
	tw_check("3h failed gen spends 0", $before_fail === TW_Credits::thumb_left($sid));
}

if (!empty($uid) && !is_wp_error($uid)) {
	TW_Credits::set_daily_used(90, $uid);
	$before_w = TW_Credits::writing_left($uid);
	$before_d = TW_Credits::daily_used($uid);
	$before_p = TW_Credits::project_used();
	$check = TW_Credits::assert_units(100, $uid, true);
	tw_check("4a full 100 blocked at 90 used", is_wp_error($check) && "tw_daily_limit" === $check->get_error_code(), is_wp_error($check) ? $check->get_error_message() : "not blocked");
	tw_check("4b no credit spent", $before_w === TW_Credits::writing_left($uid));
	tw_check("4c no units spent", $before_d === TW_Credits::daily_used($uid) && $before_p === TW_Credits::project_used());
}

if (!empty($sid) && !is_wp_error($sid)) {
	TW_Credits::set_daily_used(0, $sid);
	TW_Credits::set_project_used(9950);
	$before_w = TW_Credits::writing_left($sid);
	$check = TW_Credits::assert_units(100, $sid, true);
	tw_check("5a project full wants queue", is_wp_error($check) && "tw_quota_queue" === $check->get_error_code(), is_wp_error($check) ? $check->get_error_message() : "not queue");
	$queued = TW_Credits::enqueue_push("dryrunTestVideo1", array("pkg"=>array("title"=>"Dry run"),"opts"=>array(),"duration"=>0,"fingerprint"=>"dry","source"=>"test"), $sid, 100);
	tw_check("5b queued", !is_wp_error($queued) && !empty($queued["queued"]), is_wp_error($queued) ? $queued->get_error_message() : wp_json_encode($queued));
	if (!is_wp_error($queued)) {
		tw_check("5c position shown", !empty($queued["position"]) && false !== stripos($queued["message"], "position"), $queued["message"]);
		tw_check("5d credit not spent", empty($queued["credit_spent"]) && $before_w === TW_Credits::writing_left($sid));
	}
	$found = false;
	foreach (TW_Credits::queue_entries(20) as $e) {
		if ((string)$e["video_id"] === "dryrunTestVideo1" && (int)$e["user_id"] === (int)$sid) { $found = true; break; }
	}
	tw_check("5e admin queue list shows it", $found);
}

$today = TW_Launch::sync_today_quota();
tw_check("6a project_used readable", isset($today["project_used"]), "today=" . $today["project_used"]);
$history = TW_Launch::quota_history();
tw_check("6b 14 day history loads", is_array($history), "rows=" . count($history));
$gates = TW_Launch::exit_gate();
$gate20 = null;
foreach ($gates as $g) { if (false !== stripos($g["label"], "20 real beta")) { $gate20 = $g; break; } }
tw_check("6c 20 users not yet met", $gate20 && empty($gate20["met"]), $gate20 ? $gate20["detail"] : "missing");
$beta_n = TW_Credits::beta_active_count();
tw_check("6d beta count X of 25", $beta_n >= 0 && $beta_n <= 25, "Beta users: {$beta_n} of 25");

TW_Credits::set_project_used($project_before);
echo "\nRestored project_used to {$project_before}\n";
foreach (array($uid ?? 0, $sid ?? 0) as $cleanup_id) {
	if ($cleanup_id && !is_wp_error($cleanup_id)) {
		TW_Context::set_user($cleanup_id);
		TW_Store::delete("gemini_key");
	}
}
TW_Context::clear();
echo "\n=== Summary: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);


