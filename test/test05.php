<?php
// test05.php -- HotCRP paper submission tests
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

declare(strict_types=1);
require_once(preg_replace('/\/test\/[^\/]+/', '/test/setup.php', __FILE__));
$Conf->save_setting("sub_open", 1);
$Conf->save_setting("sub_update", Conf::$now + 100);
$Conf->save_setting("sub_sub", Conf::$now + 100);
$Conf->save_setting("opt.contentHashMethod", 1, "sha1");
$Conf->save_setting("rev_open", 1);

// load users
$user_chair = $Conf->checked_user_by_email("chair@_.com");
$user_estrin = $Conf->checked_user_by_email("estrin@usc.edu"); // pc
$user_varghese = $Conf->checked_user_by_email("varghese@ccrc.wustl.edu"); // pc red
$user_sally = $Conf->checked_user_by_email("floyd@ee.lbl.gov"); // pc red blue
$user_nobody = new Contact;

$ps = new PaperStatus($Conf, $user_estrin);

$paper1a = $ps->paper_json(1);
xassert_eqq($paper1a->title, "Scalable Timers for Soft State Protocols");
$paper1a_pcc = $paper1a->pc_conflicts;
'@phan-var-force object $paper1a_pcc';
xassert_eqq($paper1a_pcc->{"estrin@usc.edu"}, "author");
$paper1 = $Conf->checked_paper_by_id(1);
xassert_eqq($paper1->conflict_type($user_estrin), CONFLICT_AUTHOR);

$ps->save_paper_json((object) ["id" => 1, "title" => "Scalable Timers? for Soft State Protocols"]);
xassert_paper_status($ps);
$paper1->invalidate_conflicts();
xassert_eqq($paper1->conflict_type($user_estrin), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);

$paper1b = $ps->paper_json(1);

xassert_eqq($paper1b->title, "Scalable Timers? for Soft State Protocols");
$paper1b->title = $paper1a->title;
$paper1b->submitted_at = $paper1a->submitted_at;
$s1 = json_encode($paper1a);
$s2 = json_encode($paper1b);
xassert_eqq($s1, $s2);
if ($s1 !== $s2) {
    while (substr($s1, 0, 30) === substr($s2, 0, 30)) {
        $s1 = substr($s1, 10);
        $s2 = substr($s2, 10);
    }
    error_log("   > $s1\n   > $s2");
}

$doc = DocumentInfo::make_uploaded_file([
        "error" => UPLOAD_ERR_OK, "name" => "amazing-sample.pdf",
        "tmp_name" => SiteLoader::find("etc/sample.pdf"),
        "type" => "application/pdf"
    ], -1, DTYPE_SUBMISSION, $Conf);
xassert_eqq($doc->content_text_signature(), "starts with “%PDF-1.2”");
$ps->save_paper_json((object) ["id" => 1, "submission" => $doc]);
xassert_paper_status($ps);

$paper1c = $ps->paper_json(1);
xassert_eqq($paper1c->submission->hash, "2f1bccbf1e0e98004c01ef5b26eb9619f363e38e");

$ps = new PaperStatus($Conf);

$paper2a = $ps->paper_json(2);
$ps->save_paper_json(json_decode("{\"id\":2,\"submission\":{\"content\":\"%PDF-hello\\n\",\"type\":\"application/pdf\"}}"));
xassert_paper_status($ps);
xassert(ConfInvariants::test_document_inactive($Conf));

$paper2b = $ps->paper_json(2);
xassert_eqq($paper2b->submission->hash, "24aaabecc9fac961d52ae62f620a47f04facc2ce");

$ps->save_paper_json(json_decode("{\"id\":2,\"final\":{\"content\":\"%PDF-goodbye\\n\",\"type\":\"application/pdf\"}}"));
xassert_paper_status($ps);
xassert(ConfInvariants::test_document_inactive($Conf));

$paper2 = $ps->paper_json(2);
xassert_eqq($paper2->submission->hash, "24aaabecc9fac961d52ae62f620a47f04facc2ce");
xassert_eqq($paper2->final->hash, "e04c778a0af702582bb0e9345fab6540acb28e45");

$ps->save_paper_json(json_decode("{\"id\":2,\"submission\":{\"content\":\"%PDF-again hello\\n\",\"type\":\"application/pdf\"}}"));
xassert_paper_status($ps);
xassert(ConfInvariants::test_document_inactive($Conf));

$paper2 = $ps->paper_json(2);
xassert_eqq($paper2->submission->hash, "30240fac8417b80709c72156b7f7f7ad95b34a2b");
xassert_eqq($paper2->final->hash, "e04c778a0af702582bb0e9345fab6540acb28e45");
$paper2 = $user_estrin->checked_paper_by_id(2);
xassert_eqq(bin2hex($paper2->sha1), "e04c778a0af702582bb0e9345fab6540acb28e45");

// test new-style options storage
$options = $Conf->setting_json("options");
xassert(!array_filter((array) $options, function ($o) { return $o->id === 2; }));
$options[] = (object) ["id" => 2, "name" => "Attachments", "abbr" => "attachments", "type" => "attachments", "position" => 2];
$Conf->save_setting("options", 1, json_encode($options));
$Conf->invalidate_caches(["options" => true]);

$ps->save_paper_json(json_decode("{\"id\":2,\"options\":{\"attachments\":[{\"content\":\"%PDF-1\", \"type\":\"application/pdf\"}, {\"content\":\"%PDF-2\", \"type\":\"application/pdf\"}]}}"));
xassert_paper_status($ps);
xassert(ConfInvariants::test_document_inactive($Conf));

$paper2 = $user_estrin->checked_paper_by_id(2);
$docs = $paper2->option(2)->documents();
xassert_eqq(count($docs), 2);
xassert($docs[0]->check_text_hash("4c18e2ec1d1e6d9e53f57499a66aeb691d687370"));
$d0psid = $docs[0]->paperStorageId;
xassert($docs[1]->check_text_hash("2e866582768e8954f55b974a2ad8503ef90717ab"));
$d1psid = $docs[1]->paperStorageId;

$ps->save_paper_json(json_decode("{\"id\":2,\"options\":{\"attachments\":[{\"content\":\"%PDF-1\", \"sha1\": \"4c18e2ec1d1e6d9e53f57499a66aeb691d687370\", \"type\":\"application/pdf\"}, {\"content\":\"%PDF-2\", \"sha1\": \"2e866582768e8954f55b974a2ad8503ef90717ab\", \"type\":\"application/pdf\"}, {\"content\":\"%PDF-2\", \"sha1\": \"2e866582768e8954f55b974a2ad8503ef90717ab\", \"type\":\"application/pdf\"}]}}"));
xassert_paper_status($ps);
xassert(ConfInvariants::test_document_inactive($Conf));

$paper2 = $user_estrin->checked_paper_by_id(2);
$docs = $paper2->option(2)->documents();
xassert_eqq(count($docs), 3);
xassert($docs[0]->check_text_hash("4c18e2ec1d1e6d9e53f57499a66aeb691d687370"));
xassert_eqq($docs[0]->paperStorageId, $d0psid);
xassert($docs[1]->check_text_hash("2e866582768e8954f55b974a2ad8503ef90717ab"));
xassert_eqq($docs[1]->paperStorageId, $d1psid);
xassert($docs[2]->check_text_hash("2e866582768e8954f55b974a2ad8503ef90717ab"));
xassert_eqq($docs[2]->paperStorageId, $d1psid);

// backwards compatibility for option storage
$Conf->qe("delete from PaperOption where paperId=2 and optionId=2");
$Conf->qe("insert into PaperOption (paperId,optionId,value,data) values (2,2,$d0psid,'0'),(2,2,$d1psid,'1')");
$paper2 = $user_estrin->checked_paper_by_id(2);
$docs = $paper2->option(2)->documents();
xassert_eqq(count($docs), 2);
xassert($docs[0]->check_text_hash("4c18e2ec1d1e6d9e53f57499a66aeb691d687370"));
xassert_eqq($docs[0]->paperStorageId, $d0psid);
xassert($docs[1]->check_text_hash("2e866582768e8954f55b974a2ad8503ef90717ab"));
xassert_eqq($docs[1]->paperStorageId, $d1psid);

// new-style JSON representation
$ps->save_paper_json(json_decode("{\"id\":2,\"attachments\":[{\"content\":\"%PDF-2\", \"type\":\"application/pdf\"}, {\"content\":\"%PDF-1\", \"type\":\"application/pdf\"}]}"));
xassert_paper_status($ps);
xassert(ConfInvariants::test_document_inactive($Conf));

$paper2 = $user_estrin->checked_paper_by_id(2);
$docs = $paper2->option(2)->documents();
xassert_eqq(count($docs), 2);
xassert($docs[0]->check_text_hash("2e866582768e8954f55b974a2ad8503ef90717ab"));
xassert($docs[1]->check_text_hash("4c18e2ec1d1e6d9e53f57499a66aeb691d687370"));

// test SHA-256
$Conf->save_setting("opt.contentHashMethod", 1, "sha256");

$ps->save_paper_json(json_decode("{\"id\":3,\"submission\":{\"content\":\"%PDF-whatever\\n\",\"type\":\"application/pdf\"}}"));
xassert_paper_status($ps);
xassert(ConfInvariants::test_document_inactive($Conf));

$paper3 = $user_estrin->checked_paper_by_id(3);
xassert_eqq($paper3->sha1, "sha2-" . hex2bin("38b74d4ab9d3897b0166aa975e5e00dd2861a218fad7ec8fa08921fff7f0f0f4"));
xassert_eqq($paper3->document(DTYPE_SUBMISSION)->text_hash(), "sha2-38b74d4ab9d3897b0166aa975e5e00dd2861a218fad7ec8fa08921fff7f0f0f4");

$paper3b = $ps->paper_json(3);
xassert_eqq($paper3b->submission->hash, "sha2-38b74d4ab9d3897b0166aa975e5e00dd2861a218fad7ec8fa08921fff7f0f0f4");

// test submitting a new paper
$ps = new PaperStatus($Conf, $user_estrin);
xassert($ps->prepare_save_paper_web(new Qrequest("POST", ["title" => "New paper", "abstract" => "This is an abstract\r\n", "has_authors" => "1", "authors:name_1" => "Bobby Flay", "authors:email_1" => "flay@_.com"]), null, "update"));
xassert_paper_status($ps);
xassert($ps->diffs["title"]);
xassert($ps->diffs["abstract"]);
xassert($ps->diffs["authors"]);
xassert($ps->diffs["contacts"]);
xassert($ps->execute_save());
xassert_paper_status($ps);

$newpaper = $user_estrin->checked_paper_by_id($ps->paperId);
xassert($newpaper);
xassert_eqq($newpaper->title, "New paper");
xassert_eqq($newpaper->abstract, "This is an abstract");
xassert_eqq($newpaper->abstract_text(), "This is an abstract");
xassert_eqq(count($newpaper->author_list()), 1);
$aus = $newpaper->author_list();
xassert_eqq($aus[0]->firstName, "Bobby");
xassert_eqq($aus[0]->lastName, "Flay");
xassert_eqq($aus[0]->email, "flay@_.com");
xassert($newpaper->timeSubmitted <= 0);
xassert($newpaper->timeWithdrawn <= 0);
xassert_eqq($newpaper->conflict_type($user_estrin), CONFLICT_CONTACTAUTHOR);

$ps = new PaperStatus($Conf, $user_estrin);
xassert($ps->prepare_save_paper_web(new Qrequest("POST", []), $newpaper, "submit"));
xassert_array_eqq(array_keys($ps->diffs), ["status"], true);
xassert($ps->execute_save());
xassert_paper_status($ps);

$newpaper = $user_estrin->checked_paper_by_id($ps->paperId);
xassert($newpaper);
xassert_eqq($newpaper->title, "New paper");
xassert_eqq($newpaper->abstract, "This is an abstract");
xassert_eqq($newpaper->abstract_text(), "This is an abstract");
xassert_eqq(count($newpaper->author_list()), 1);
$aus = $newpaper->author_list();
xassert_eqq($aus[0]->firstName, "Bobby");
xassert_eqq($aus[0]->lastName, "Flay");
xassert_eqq($aus[0]->email, "flay@_.com");
xassert($newpaper->timeSubmitted > 0);
xassert($newpaper->timeWithdrawn <= 0);
xassert_eqq($newpaper->conflict_type($user_estrin), CONFLICT_CONTACTAUTHOR);

// test submitting a new paper from scratch
$ps = new PaperStatus($Conf, $user_estrin);
xassert($ps->prepare_save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "title" => "New paper", "abstract" => "This is an abstract\r\n", "has_authors" => "1", "authors:name_1" => "Bobby Flay", "authors:email_1" => "flay@_.com"]), null, "update"));
xassert_paper_status($ps);
xassert($ps->diffs["title"]);
xassert($ps->diffs["abstract"]);
xassert($ps->diffs["authors"]);
xassert($ps->diffs["contacts"]);
xassert($ps->execute_save());
xassert_paper_status($ps);

$newpaper = $user_estrin->checked_paper_by_id($ps->paperId);
xassert($newpaper);
xassert_eqq($newpaper->title, "New paper");
xassert_eqq($newpaper->abstract, "This is an abstract");
xassert_eqq($newpaper->abstract_text(), "This is an abstract");
xassert_eqq(count($newpaper->author_list()), 1);
$aus = $newpaper->author_list();
xassert_eqq($aus[0]->firstName, "Bobby");
xassert_eqq($aus[0]->lastName, "Flay");
xassert_eqq($aus[0]->email, "flay@_.com");
xassert($newpaper->timeSubmitted > 0);
xassert($newpaper->timeWithdrawn <= 0);
xassert_eqq($newpaper->conflict_type($user_estrin), CONFLICT_CONTACTAUTHOR);

// saving explicitly-empty contact list still assigns a contact
$ps = new PaperStatus($Conf, $user_estrin);
xassert($ps->prepare_save_paper_web(new Qrequest("POST", ["title" => "New paper", "abstract" => "This is an abstract\r\n", "has_authors" => "1", "authors:name_1" => "Bobby Flay", "authors:email_1" => "flay@_.com", "has_contacts" => 1]), null, "update"));
xassert($ps->diffs["title"]);
xassert($ps->diffs["abstract"]);
xassert($ps->diffs["authors"]);
xassert($ps->diffs["contacts"]);
xassert($ps->execute_save());
xassert_paper_status($ps);

$newpaperx = $user_estrin->checked_paper_by_id($ps->paperId);
xassert($newpaperx);
xassert_eqq($newpaperx->title, "New paper");
xassert_eqq($newpaperx->abstract, "This is an abstract");
xassert_eqq($newpaperx->abstract_text(), "This is an abstract");
xassert_eqq(count($newpaperx->author_list()), 1);
$aus = $newpaperx->author_list();
xassert_eqq($aus[0]->firstName, "Bobby");
xassert_eqq($aus[0]->lastName, "Flay");
xassert_eqq($aus[0]->email, "flay@_.com");
xassert($newpaperx->timeSubmitted <= 0);
xassert($newpaperx->timeWithdrawn <= 0);
xassert_eqq($newpaperx->conflict_type($user_estrin), CONFLICT_CONTACTAUTHOR);

$ps = new PaperStatus($Conf, $user_estrin);
xassert($ps->prepare_save_paper_web(new Qrequest("POST", ["opt1" => "10", "has_opt1" => "1"]), $newpaper, "update"));
xassert_array_eqq(array_keys($ps->diffs), ["calories", "status"], true);
xassert($ps->execute_save());
xassert_paper_status($ps);

$ps = new PaperStatus($Conf, $user_estrin);
xassert(!$ps->prepare_save_paper_web(new Qrequest("POST", ["opt1" => "10xxxxx", "has_opt1" => "1"]), $newpaper, "update"));
xassert_array_eqq(array_keys($ps->diffs), [], true);
xassert($ps->has_error_at("opt1"));

$ps = new PaperStatus($Conf, $user_estrin);
xassert($ps->prepare_save_paper_web(new Qrequest("POST", ["opt1" => "none", "has_opt1" => "1"]), $newpaper, "update"));
xassert_array_eqq(array_keys($ps->diffs), ["calories"], true);
xassert_paper_status($ps);

$newpaper = $user_estrin->checked_paper_by_id($ps->paperId);
xassert($newpaper);
xassert_eqq($newpaper->title, "New paper");
xassert_eqq($newpaper->abstract, "This is an abstract");
xassert_eqq($newpaper->abstract_text(), "This is an abstract");
xassert_eqq(count($newpaper->author_list()), 1);
$aus = $newpaper->author_list();
xassert_eqq($aus[0]->firstName, "Bobby");
xassert_eqq($aus[0]->lastName, "Flay");
xassert_eqq($aus[0]->email, "flay@_.com");
xassert($newpaper->timeSubmitted <= 0);
xassert($newpaper->timeWithdrawn <= 0);
xassert_eqq($newpaper->option(1)->value, 10);

// check old author entry syntax
$ps = new PaperStatus($Conf, $user_estrin);
xassert($ps->prepare_save_paper_web(new Qrequest("POST", ["has_authors" => "1", "auname1" => "Robert Flay", "auemail1" => "flay@_.com"]), $newpaper, "update"));
xassert($ps->diffs["authors"]);
xassert($ps->execute_save());
xassert_paper_status($ps);

$newpaper = $user_estrin->checked_paper_by_id($ps->paperId);
xassert($newpaper);
xassert_eqq($newpaper->title, "New paper");
xassert_eqq($newpaper->abstract, "This is an abstract");
xassert_eqq($newpaper->abstract_text(), "This is an abstract");
xassert_eqq(count($newpaper->author_list()), 1);
$aus = $newpaper->author_list();
xassert_eqq($aus[0]->firstName, "Robert");
xassert_eqq($aus[0]->lastName, "Flay");
xassert_eqq($aus[0]->email, "flay@_.com");
xassert($newpaper->timeSubmitted <= 0);
xassert($newpaper->timeWithdrawn <= 0);
xassert_eqq($newpaper->option(1)->value, 10);

// save a new paper
$qreq = new Qrequest("POST", ["submitpaper" => 1, "has_opt2" => "1", "has_opt2_new_1" => "1", "title" => "Paper about mantis shrimp", "has_authors" => "1", "authors:name_1" => "David Attenborough", "authors:email_1" => "atten@_.com", "authors:aff_1" => "BBC", "abstract" => "They see lots of colors.", "has_submission" => "1"]);
$qreq->set_file("submission", ["name" => "amazing-sample.pdf", "tmp_name" => SiteLoader::find("etc/sample.pdf"), "type" => "application/pdf", "error" => UPLOAD_ERR_OK]);
$qreq->set_file("opt2_new_1", ["name" => "attachment1.pdf", "type" => "application/pdf", "content" => "%PDF-whatever\n", "error" => UPLOAD_ERR_OK]);
$ps = new PaperStatus($Conf, $user_estrin);
xassert($ps->prepare_save_paper_web($qreq, null, "update"));
xassert($ps->diffs["title"]);
xassert($ps->diffs["abstract"]);
xassert($ps->diffs["authors"]);
xassert($ps->diffs["submission"]);
xassert($ps->execute_save());
xassert_paper_status($ps);
$npid1 = $ps->paperId;

$newpaper = $user_estrin->checked_paper_by_id($npid1);
xassert($newpaper);
xassert_eqq($newpaper->title, "Paper about mantis shrimp");
xassert_eqq($newpaper->abstract, "They see lots of colors.");
xassert_eqq($newpaper->abstract_text(), "They see lots of colors.");
xassert_eqq(count($newpaper->author_list()), 1);
$aus = $newpaper->author_list();
xassert_eqq($aus[0]->firstName, "David");
xassert_eqq($aus[0]->lastName, "Attenborough");
xassert_eqq($aus[0]->email, "atten@_.com");
xassert($newpaper->timeSubmitted > 0);
xassert($newpaper->timeWithdrawn <= 0);
xassert(!$newpaper->option(1));
xassert(!!$newpaper->option(2));
xassert(count($newpaper->force_option(0)->documents()) === 1);
xassert_eqq($newpaper->force_option(0)->document(0)->text_hash(), "sha2-d16c7976d9081368c7dca2da3a771065c3222069a1ad80dcd99d972b2efadc8b");
xassert(count($newpaper->option(2)->documents()) === 1);
xassert_eqq($newpaper->option(2)->document(0)->text_hash(), "sha2-38b74d4ab9d3897b0166aa975e5e00dd2861a218fad7ec8fa08921fff7f0f0f4");
xassert($newpaper->has_author($user_estrin));

// some erroneous saves concerning required fields
$qreq = new Qrequest("POST", ["submitpaper" => 1, "has_authors" => "1", "authors:name_1" => "David Attenborough", "authors:email_1" => "atten@_.com", "authors:affiliation_1" => "BBC", "abstract" => "They see lots of colors.", "has_submission" => "1"]);
$qreq->set_file("submission", ["name" => "amazing-sample.pdf", "tmp_name" => SiteLoader::find("etc/sample.pdf"), "type" => "application/pdf", "error" => UPLOAD_ERR_OK]);
$ps = new PaperStatus($Conf, $user_estrin);
$ps->prepare_save_paper_web($qreq, null, "update");
xassert($ps->has_error_at("title"));
xassert_eqq(count($ps->error_fields()), 1);
xassert_eq($ps->error_texts(), ["Entry required."]);

$qreq = new Qrequest("POST", ["submitpaper" => 1, "title" => "", "has_authors" => "1", "authors:name_1" => "David Attenborough", "authors:email_1" => "atten@_.com", "authors:affiliation_1" => "BBC", "abstract" => "They see lots of colors.", "has_submission" => "1"]);
$qreq->set_file("submission", ["name" => "amazing-sample.pdf", "tmp_name" => SiteLoader::find("etc/sample.pdf"), "type" => "application/pdf", "error" => UPLOAD_ERR_OK]);
$ps = new PaperStatus($Conf, $user_estrin);
$ps->prepare_save_paper_web($qreq, null, "update");
xassert($ps->has_error_at("title"));
xassert_eqq(count($ps->error_fields()), 1);
xassert_eq($ps->error_texts(), ["Entry required."]);

$qreq = new Qrequest("POST", ["submitpaper" => 1, "title" => "Another Mantis Shrimp Paper", "has_authors" => "1", "authors:name_1" => "David Attenborough", "authors:email_1" => "atten@_.com", "authors:affiliation_1" => "BBC", "has_submission" => "1"]);
$qreq->set_file("submission", ["name" => "amazing-sample.pdf", "tmp_name" => SiteLoader::find("etc/sample.pdf"), "type" => "application/pdf", "error" => UPLOAD_ERR_OK]);
$ps = new PaperStatus($Conf, $user_estrin);
$ps->prepare_save_paper_web($qreq, null, "update");
xassert($ps->has_error_at("abstract"));
xassert_eqq(count($ps->error_fields()), 1);
xassert_eq($ps->error_texts(), ["Entry required."]);

$Conf->set_opt("noAbstract", 1);
$Conf->invalidate_caches(["options" => true]);

$qreq = new Qrequest("POST", ["submitpaper" => 1, "title" => "Another Mantis Shrimp Paper", "has_authors" => "1", "authors:name_1" => "David Attenborough", "authors:email_1" => "atten@_.com", "authors:affiliation_1" => "BBC", "has_submission" => "1"]);
$qreq->set_file("submission", ["name" => "amazing-sample.pdf", "tmp_name" => SiteLoader::find("etc/sample.pdf"), "type" => "application/pdf", "error" => UPLOAD_ERR_OK]);
$ps = new PaperStatus($Conf, $user_estrin);
$ps->prepare_save_paper_web($qreq, null, "update");
xassert(!$ps->has_error_at("abstract"));
xassert_eqq(count($ps->error_fields()), 0);
xassert_eq($ps->error_texts(), []);

// abstract saving
$nprow1 = $user_estrin->checked_paper_by_id($npid1);
xassert_eqq($nprow1->abstract, "They see lots of colors.");

$ps = new PaperStatus($Conf, $user_estrin);
$ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "abstract" => " They\nsee\r\nlots of\n\n\ncolors. \n\n\n"]), $nprow1, "update");
xassert(!$ps->has_problem());
xassert_array_eqq(array_keys($ps->diffs), ["abstract"], true);
$nprow1 = $user_estrin->checked_paper_by_id($npid1);
xassert_eqq($nprow1->abstract, "They\nsee\r\nlots of\n\n\ncolors.");

// collaborators saving
$nprow1 = $user_estrin->checked_paper_by_id($npid1);
xassert_eqq($nprow1->collaborators(), "");

$ps = new PaperStatus($Conf, $user_estrin);
$ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "collaborators" => "  John Fart\rMIT\n\nButt Man (UCLA)"]), $nprow1, "update");
xassert_paper_status($ps, MessageSet::WARNING);
xassert_array_eqq(array_keys($ps->diffs), ["collaborators"], true);
$nprow1 = $user_estrin->checked_paper_by_id($npid1);
xassert_eqq($nprow1->collaborators(), "John Fart\nAll (MIT)\n\nButt Man (UCLA)");

$ps = new PaperStatus($Conf, $user_estrin);
$ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "collaborators" => "Sal Stolfo, Guofei Gu, Manos Antonakakis, Roberto Perdisci, Weidong Cui, Xiapu Luo, Rocky Chang, Kapil Singh, Helen Wang, Zhichun Li, Junjie Zhang, David Dagon, Nick Feamster, Phil Porras."]), $nprow1, "update");
xassert_paper_status($ps, MessageSet::WARNING);
xassert_array_eqq(array_keys($ps->diffs), ["collaborators"], true);
$nprow1 = $user_estrin->checked_paper_by_id($npid1);
xassert_eqq($nprow1->collaborators(), "Sal Stolfo
Guofei Gu
Manos Antonakakis
Roberto Perdisci
Weidong Cui
Xiapu Luo
Rocky Chang
Kapil Singh
Helen Wang
Zhichun Li
Junjie Zhang
David Dagon
Nick Feamster
Phil Porras.");

// the collaborators are too long
$long_collab = [];
for ($i = 0; $i !== 1000; ++$i) {
    $long_collab[] = "Collaborator $i (MIT)";
}
$long_collab = join("\n", $long_collab);
$ps = new PaperStatus($Conf, $user_estrin);
$ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "collaborators" => $long_collab]), $nprow1, "update");
xassert_paper_status($ps);
xassert_array_eqq(array_keys($ps->diffs), ["collaborators"], true);
$nprow1 = $user_estrin->checked_paper_by_id($npid1);
xassert_eqq($nprow1->collaborators, null);
xassert_eqq(json_encode_db($nprow1->dataOverflow), json_encode_db(["collaborators" => $long_collab]));
xassert_eqq($nprow1->collaborators(), $long_collab);

// the collaborators are short again
$ps = new PaperStatus($Conf, $user_estrin);
$ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "collaborators" => "One guy (MIT)"]), $nprow1, "update");
xassert_paper_status($ps);
xassert_array_eqq(array_keys($ps->diffs), ["collaborators"], true);
$nprow1 = $user_estrin->checked_paper_by_id($npid1);
xassert_eqq($nprow1->collaborators(), "One guy (MIT)");
xassert_eqq($nprow1->dataOverflow, null);

// topic saving
$Conf->qe("insert into TopicArea (topicName) values ('Cloud computing'), ('Architecture'), ('Security'), ('Cloud networking')");
$Conf->save_setting("has_topics", 1);
$Conf->invalidate_topics();

$tset = $Conf->topic_set();
xassert_eqq($tset[1], "Cloud computing");
xassert_eqq($tset[2], "Architecture");
xassert_eqq($tset[3], "Security");
xassert_eqq($tset[4], "Cloud networking");

$nprow1 = $user_estrin->checked_paper_by_id($npid1);
xassert_eqq($nprow1->topic_list(), []);

$ps = new PaperStatus($Conf, $user_estrin);
$ps->save_paper_json((object) [
    "id" => $npid1,
    "topics" => ["Cloud computing"]
]);
xassert(!$ps->has_problem());
xassert_array_eqq(array_keys($ps->diffs), ["topics"]);
$nprow1->invalidate_topics();
xassert_eqq($nprow1->topic_list(), [1]);

$ps->save_paper_json((object) [
    "id" => $npid1,
    "topics" => (object) ["Cloud computing" => true, "Security" => true]
]);
xassert(!$ps->has_problem());
xassert_array_eqq(array_keys($ps->diffs), ["topics"]);
$nprow1->invalidate_topics();
xassert_eqq($nprow1->topic_list(), [1, 3]);

$ps->save_paper_json((object) [
    "id" => $npid1,
    "topics" => [2, 4]
]);
xassert(!$ps->has_problem());
xassert_array_eqq(array_keys($ps->diffs), ["topics"]);
$nprow1->invalidate_topics();
xassert_eqq($nprow1->topic_list(), [2, 4]);

// extended topic saves
$ps->save_paper_json((object) [
    "id" => $npid1,
    "topics" => ["architecture", "security"]
]);
xassert(!$ps->has_problem());
xassert_array_eqq(array_keys($ps->diffs), ["topics"]);
$nprow1->invalidate_topics();
xassert_eqq($nprow1->topic_list(), [2, 3]);

$ps->save_paper_json((object) [
    "id" => $npid1,
    "topics" => ["fartchitecture"]
]);
xassert_paper_status($ps, MessageSet::WARNING);
xassert($ps->has_problem());
xassert_eqq($ps->message_texts_at("topics"), ["Unknown topic ignored (fartchitecture)."]);
$nprow1->invalidate_topics();
xassert_eqq($nprow1->topic_list(), []); // XXX should be unchanged

$ps = new PaperStatus($Conf, $user_estrin, ["add_topics" => true]);
$ps->save_paper_json((object) [
    "id" => $npid1,
    "topics" => ["fartchitecture", "architecture"]
]);
xassert(!$ps->has_problem());
xassert_array_eqq(array_keys($ps->diffs), ["topics"]);
$nprow1->invalidate_topics();
xassert_eqq($nprow1->topic_list(), [2, 5]);

$qreq = new Qrequest("POST", ["submitpaper" => 1, "has_topics" => 1, "top1" => 1, "top5" => 1]);
$ps->save_paper_web($qreq, $nprow1, "update");
xassert(!$ps->has_problem());
$nprow1->invalidate_topics();
xassert_eqq($nprow1->topic_list(), [1, 5]);

// extended pc conflicts
function pc_conflict_keys($prow) {
    return array_keys($prow->pc_conflicts());
}
function pc_conflict_types($prow) {
    return array_map(function ($cflt) { return $cflt->conflictType; }, $prow->pc_conflicts());
}
function contact_emails($prow) {
    $e = array_map(function ($cflt) { return $cflt->email; }, $prow->contacts(true));
    sort($e);
    return $e;
}
xassert_eqq(pc_conflict_keys($nprow1), [$user_estrin->contactId]);
xassert_eqq(pc_conflict_types($nprow1), [$user_estrin->contactId => CONFLICT_CONTACTAUTHOR]);

$ps->save_paper_json((object) [
    "id" => $npid1, "pc_conflicts" => []
]);
xassert(!$ps->has_problem());
xassert_eqq(count($ps->diffs), 0);
$nprow1->invalidate_conflicts();
xassert_eqq(pc_conflict_keys($nprow1), [$user_estrin->contactId]);

$ps->save_paper_json((object) [
    "id" => $npid1, "pc_conflicts" => [$user_varghese->email => true, $user_sally->email => true]
]);
xassert(!$ps->has_problem());
xassert_array_eqq(array_keys($ps->diffs), ["pc_conflicts"], true);
$nprow1->invalidate_conflicts();
xassert_eqq(pc_conflict_keys($nprow1),
    [$user_estrin->contactId, $user_varghese->contactId, $user_sally->contactId]);

$ps->save_paper_json((object) [
    "id" => $npid1, "pc_conflicts" => []
]);
xassert(!$ps->has_problem());
xassert_array_eqq(array_keys($ps->diffs), ["pc_conflicts"], true);
$nprow1->invalidate_conflicts();
xassert_eqq(pc_conflict_keys($nprow1), [$user_estrin->contactId]);

$ps->save_paper_json((object) [
    "id" => $npid1, "pc_conflicts" => [$user_varghese->email]
]);
xassert(!$ps->has_problem());
xassert_array_eqq(array_keys($ps->diffs), ["pc_conflicts"], true);
$nprow1->invalidate_conflicts();
xassert_eqq(pc_conflict_keys($nprow1), [$user_estrin->contactId, $user_varghese->contactId]);

$ps->save_paper_json((object) [
    "id" => $npid1, "pc_conflicts" => [$user_varghese->email, "notpc@no.com"]
]);
xassert($ps->has_problem());
xassert_paper_status($ps, MessageSet::WARNING);
xassert_array_eqq(array_keys($ps->diffs), [], true);
$nprow1->invalidate_conflicts();
xassert_eqq(pc_conflict_keys($nprow1), [$user_estrin->contactId, $user_varghese->contactId]);
xassert_eqq($nprow1->conflict_type($user_estrin), CONFLICT_CONTACTAUTHOR);
xassert_eqq($nprow1->conflict_type($user_varghese), Conflict::GENERAL);

$ps->save_paper_json((object) [
    "id" => $npid1, "pc_conflicts" => [$user_varghese->email => "advisor"]
]);
xassert(!$ps->has_problem()); // XXX should have problem
xassert_array_eqq(array_keys($ps->diffs), ["pc_conflicts"], true);
$nprow1->invalidate_conflicts();
xassert_eqq(pc_conflict_keys($nprow1), [$user_estrin->contactId, $user_varghese->contactId]);
xassert_eqq($nprow1->conflict_type($user_estrin), CONFLICT_CONTACTAUTHOR);
xassert_eqq($nprow1->conflict_type($user_varghese), 4);

$ps->save_paper_json((object) [
    "id" => $npid1, "pc_conflicts" => [$user_varghese->email => "advisor", $user_estrin->email => false, $user_chair->email => false]
]);
xassert(!$ps->has_problem()); // XXX should have problem
xassert_array_eqq(array_keys($ps->diffs), [], true);
$nprow1->invalidate_conflicts();
xassert_eqq(pc_conflict_keys($nprow1), [$user_estrin->contactId, $user_varghese->contactId]);
xassert_eqq($nprow1->conflict_type($user_estrin), CONFLICT_CONTACTAUTHOR);
xassert_eqq($nprow1->conflict_type($user_varghese), 4);

// non-chair cannot pin conflicts
$ps->save_paper_json((object) [
    "id" => $npid1, "pc_conflicts" => [$user_varghese->email => "pinned collaborator"]
]);
xassert(!$ps->has_problem()); // XXX should have problem
$nprow1->invalidate_conflicts();
xassert_eqq(pc_conflict_keys($nprow1), [$user_estrin->contactId, $user_varghese->contactId]);
xassert_eqq($nprow1->conflict_type($user_estrin), CONFLICT_CONTACTAUTHOR);
xassert_eqq($nprow1->conflict_type($user_varghese), 2);

// chair can pin conflicts
$psc = new PaperStatus($Conf, $user_chair);
$psc->save_paper_json((object) [
    "id" => $npid1, "pc_conflicts" => [$user_varghese->email => "pinned advisor"]
]);
xassert(!$psc->has_problem());
$nprow1->invalidate_conflicts();
xassert_eqq(pc_conflict_keys($nprow1), [$user_estrin->contactId, $user_varghese->contactId]);
xassert_eqq($nprow1->conflict_type($user_estrin), CONFLICT_CONTACTAUTHOR);
xassert_eqq($nprow1->conflict_type($user_varghese), 5);

// non-chair cannot change pinned conflicts
$ps->save_paper_json((object) [
    "id" => $npid1, "pc_conflicts" => [$user_varghese->email => "pinned collaborator"]
]);
xassert(!$ps->has_problem()); // XXX should have problem
$nprow1->invalidate_conflicts();
xassert_eqq(pc_conflict_keys($nprow1), [$user_estrin->contactId, $user_varghese->contactId]);
xassert_eqq($nprow1->conflict_type($user_estrin), CONFLICT_CONTACTAUTHOR);
xassert_eqq($nprow1->conflict_type($user_varghese), 5);

// contact author cannot remove themselves
$ps->save_paper_json((object) [
    "id" => $npid1, "contacts" => []
]);
xassert($ps->has_problem());
xassert_eqq($ps->message_texts_at("contacts"), ["Each submission must have at least one contact.", "You can’t remove yourself from the submission’s contacts. (Ask another contact to remove you.)"]);

$ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "has_contacts" => 1, "contacts:email_1" => "estrin@usc.edu"]), $nprow1, "update");
xassert($ps->has_problem());
xassert_eqq($ps->message_texts_at("contacts"), ["Each submission must have at least one contact.", "You can’t remove yourself from the submission’s contacts. (Ask another contact to remove you.)"]);

$ps->save_paper_json((object) [
    "id" => $npid1, "contacts" => ["estrin@usc.edu"]
]);
xassert(!$ps->has_problem());
xassert_array_eqq(array_keys($ps->diffs), [], true);

$ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "has_contacts" => 1, "contacts:email_1" => "estrin@usc.edu", "contacts:active_1" => 1, "contacts:email_2" => "", "contacts:active_2" => 1]), $nprow1, "update");
xassert(!$ps->has_problem());
xassert_array_eqq(array_keys($ps->diffs), [], true);

xassert(!$Conf->fresh_user_by_email("festrin@fusc.fedu"));
$ps->save_paper_json((object) [
    "id" => $npid1, "contacts" => ["estrin@usc.edu", (object) ["email" => "festrin@fusc.fedu", "name" => "Feborah Festrin"]]
]);
xassert(!$ps->has_problem());
xassert_array_eqq(array_keys($ps->diffs), ["contacts"], true);
$new_user = $Conf->fresh_user_by_email("festrin@fusc.fedu");
xassert(!!$new_user);
xassert_eqq($new_user->firstName, "Feborah");
xassert_eqq($new_user->lastName, "Festrin");
$festrin_cid = $new_user->contactId;
$nprow1->invalidate_conflicts();
xassert($nprow1->has_author($new_user));

xassert(!$Conf->fresh_user_by_email("gestrin@gusc.gedu"));
$ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "has_contacts" => 1, "contacts:email_1" => "estrin@usc.edu", "contacts:active_1" => 1, "contacts:email_2" => "festrin@fusc.fedu", "contacts:email_3" => "gestrin@gusc.gedu", "contacts:name_3" => "Geborah Gestrin", "contacts:active_3" => 1]), $nprow1, "update");
xassert(!$ps->has_problem());
xassert_array_eqq(array_keys($ps->diffs), ["contacts"], true);
$new_user2 = $Conf->fresh_user_by_email("gestrin@gusc.gedu");
xassert(!!$new_user2);
$gestrin_cid = $new_user2->contactId;
xassert_eqq($new_user2->firstName, "Geborah");
xassert_eqq($new_user2->lastName, "Gestrin");
$nprow1->invalidate_conflicts();
xassert(!$nprow1->has_author($new_user));
xassert($nprow1->has_author($new_user2));

$ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "has_contacts" => 1]), $nprow1, "update");
xassert(!$ps->has_problem());
xassert_array_eqq(array_keys($ps->diffs), [], true);
$nprow1->invalidate_conflicts();
xassert_array_eqq(contact_emails($nprow1), ["estrin@usc.edu", "gestrin@gusc.gedu"], true);

$ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "has_contacts" => 1, "contacts:email_1" => "atten@_.com", "contacts:active_1" => 1]), $nprow1, "update");
xassert(!$ps->has_problem());
xassert_array_eqq(array_keys($ps->diffs), ["contacts"], true);
$nprow1->invalidate_conflicts();
xassert_array_eqq(contact_emails($nprow1), ["atten@_.com", "estrin@usc.edu", "gestrin@gusc.gedu"], true);
$user_atten = $Conf->checked_user_by_email("ATTEN@_.coM");
xassert_eqq($nprow1->conflict_type($user_atten), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);

$ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "has_contacts" => 1, "contacts:email_1" => "gestrin@gusc.gedu", "contacts:email_2" => "atten@_.com"]), $nprow1, "update");
xassert(!$ps->has_problem());
xassert_array_eqq(array_keys($ps->diffs), ["contacts"], true);
$nprow1->invalidate_conflicts();
xassert_array_eqq(contact_emails($nprow1), ["atten@_.com", "estrin@usc.edu"], true);
xassert_eqq($nprow1->conflict_type($user_atten), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);

// check some primaryContactId functionality
$Conf->qe("update ContactInfo set primaryContactId=? where email=?", $festrin_cid, "gestrin@gusc.gedu");
$ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "has_authors" => "1", "authors:name_1" => "David Attenborough", "authors:email_1" => "atten@_.com", "authors:name_2" => "Geborah Gestrin", "authors:email_2" => "gestrin@gusc.gedu"]), $nprow1, "update");
xassert(!$ps->has_problem());
xassert_array_eqq(array_keys($ps->diffs), ["authors", "contacts"], true);
$nprow1 = $Conf->checked_paper_by_id($npid1);
xassert_array_eqq(contact_emails($nprow1), ["atten@_.com", "estrin@usc.edu", "festrin@fusc.fedu", "gestrin@gusc.gedu"], true);
xassert_eqq($nprow1->conflict_type($user_atten), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
xassert_eqq($nprow1->conflict_type($festrin_cid), CONFLICT_AUTHOR);
xassert_eqq($nprow1->conflict_type($gestrin_cid), CONFLICT_AUTHOR);
xassert_eqq($nprow1->conflict_type($user_estrin), CONFLICT_CONTACTAUTHOR);

$ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "has_authors" => "1", "authors:name_1" => "David Attenborough", "authors:email_1" => "atten@_.com"]), $nprow1, "update");
xassert(!$ps->has_problem());
xassert_array_eqq(array_keys($ps->diffs), ["authors", "contacts"], true);
$nprow1 = $Conf->checked_paper_by_id($npid1);
xassert_array_eqq(contact_emails($nprow1), ["atten@_.com", "estrin@usc.edu"], true);
xassert_eqq($nprow1->conflict_type($user_atten), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
xassert_eqq($nprow1->conflict_type($user_estrin), CONFLICT_CONTACTAUTHOR);

$ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "has_contacts" => "1", "contacts:email_1" => "gestrin@gusc.gedu", "contacts:active_1" => "1"]), $nprow1, "update");
xassert(!$ps->has_problem());
xassert_array_eqq(array_keys($ps->diffs), ["contacts"], true);
$nprow1->invalidate_conflicts();
xassert_array_eqq(contact_emails($nprow1), ["atten@_.com", "estrin@usc.edu", "festrin@fusc.fedu"], true);
xassert_eqq($nprow1->conflict_type($user_atten), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
xassert_eqq($nprow1->conflict_type($user_estrin), CONFLICT_CONTACTAUTHOR);
xassert_eqq($nprow1->conflict_type($festrin_cid), CONFLICT_CONTACTAUTHOR);

$ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "has_contacts" => "1", "contacts:email_1" => "gestrin@gusc.gedu"]), $nprow1, "update");
xassert(!$ps->has_problem());
xassert_array_eqq(array_keys($ps->diffs), [], true);
$nprow1->invalidate_conflicts();
xassert_array_eqq(contact_emails($nprow1), ["atten@_.com", "estrin@usc.edu", "festrin@fusc.fedu"], true);
xassert_eqq($nprow1->conflict_type($user_atten), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
xassert_eqq($nprow1->conflict_type($user_estrin), CONFLICT_CONTACTAUTHOR);
xassert_eqq($nprow1->conflict_type($festrin_cid), CONFLICT_CONTACTAUTHOR);

xassert_eqq(pc_conflict_keys($nprow1), [$user_estrin->contactId, $user_varghese->contactId]);
$Conf->qe("update ContactInfo set roles=1 where contactId=?", $festrin_cid);
$Conf->invalidate_caches(["pc" => true]);
$ps->save_paper_json((object) [
    "id" => $npid1, "pc_conflicts" => ["gestrin@gusc.gedu" => true]
]);
xassert(!$ps->has_problem());
xassert_array_eqq(array_keys($ps->diffs), ["pc_conflicts"], true);
$nprow1->invalidate_conflicts();
xassert_eqq(pc_conflict_keys($nprow1), [$user_estrin->contactId, $user_varghese->contactId, $festrin_cid]);

// check some content_text_signature functionality
$doc = new DocumentInfo(["content" => "ABCdefGHIjklMNO"], $Conf);
xassert_eqq($doc->content_text_signature(), "starts with “ABCdefGH”");
$doc = new DocumentInfo(["content" => "\x02\x00A\x80BCdefGHIjklMN"], $Conf);
xassert_eqq($doc->content_text_signature(), "starts with “\\x02\\x00A\\x80BCde”");
$doc = new DocumentInfo(["content" => ""], $Conf);
xassert_eqq($doc->content_text_signature(), "is empty");

$doc = new DocumentInfo(["content_file" => "/tmp/this-file-is-expected-not-to-exist.png.zip"], $Conf);
++Xassert::$disabled;
$s = $doc->content_text_signature();
--Xassert::$disabled;
xassert_eqq($s, "cannot be loaded");

// checks of banal interactions, including result caching
$spects = max(Conf::$now - 100, @filemtime(SiteLoader::find("src/banal")));
$Conf->save_setting("sub_banal", $spects, "letter;30;;6.5x9in");
$Conf->invalidate_caches(["options" => true]);
xassert_eq($Conf->format_spec(DTYPE_SUBMISSION)->timestamp, $spects);

$ps = new PaperStatus($Conf, null, ["content_file_prefix" => SiteLoader::$root . "/"]);
$ps->save_paper_json(json_decode("{\"id\":3,\"submission\":{\"content_file\":\"test/sample50pg.pdf\",\"type\":\"application/pdf\"}}"));
xassert_paper_status($ps);
xassert(ConfInvariants::test_document_inactive($Conf));

$paper3 = $user_estrin->checked_paper_by_id(3);
$doc = $paper3->document(DTYPE_SUBMISSION);
$cf = new CheckFormat($Conf, CheckFormat::RUN_NEVER);
xassert_eqq($doc->npages($cf), null);  // page count not yet calculated
xassert_eqq($doc->npages(), 50);       // once it IS calculated,
xassert_eqq($doc->npages($cf), 50);    // it is cached

$paper3 = $user_estrin->checked_paper_by_id(3);
$doc = $paper3->document(DTYPE_SUBMISSION);
xassert_eqq($doc->npages($cf), 50);    // ...even on reload

// check format checker; this uses result from previous npages()
$cf_nec = new CheckFormat($Conf, CheckFormat::RUN_IF_NECESSARY);
$cf_nec->check_document($paper3, $doc);
xassert_eqq(join(" ", $cf_nec->problem_fields()), "pagelimit textblock");
xassert(!$cf_nec->need_recheck());
xassert(!$cf_nec->run_attempted());
xassert_eq($paper3->pdfFormatStatus, -$spects);

// change the format spec
$Conf->save_setting("sub_banal", $spects + 1, "letter;30;;7.5x9in");
$Conf->invalidate_caches(["options" => true]);

// that actually requires rerunning banal because its cached result is truncated
$doc = $paper3->document(DTYPE_SUBMISSION);
$cf_nec->check_document($paper3, $doc);
xassert_eqq(join(" ", $cf_nec->problem_fields()), "pagelimit");
xassert(!$cf_nec->need_recheck());
xassert($cf_nec->run_attempted());

// but then the result is cached
$paper3->invalidate_documents();
$doc = $paper3->document(DTYPE_SUBMISSION);
$cf_nec->check_document($paper3, $doc);
xassert_eqq(join(" ", $cf_nec->problem_fields()), "pagelimit");
xassert(!$cf_nec->need_recheck());
xassert(!$cf_nec->run_attempted());

// new, short document
$ps->save_paper_json(json_decode("{\"id\":3,\"submission\":{\"content_file\":\"etc/sample.pdf\",\"type\":\"application/pdf\"}}"));
xassert_paper_status($ps);

// once the format is checked
$paper3 = $user_estrin->checked_paper_by_id(3);
$doc = $paper3->document(DTYPE_SUBMISSION);
$cf_nec->check_document($paper3, $doc);
xassert_eqq(join(" ", $cf_nec->problem_fields()), "");
xassert(!$cf_nec->need_recheck());
xassert($cf_nec->run_attempted());

// we can reuse the banal JSON output on another spec
$Conf->save_setting("sub_banal", $spects + 1, "letter;1;;7.5x9in");
$Conf->invalidate_caches(["options" => true]);

$paper3->invalidate_documents();
$doc = $paper3->document(DTYPE_SUBMISSION);
$cf_nec->check_document($paper3, $doc);
xassert_eqq(join(" ", $cf_nec->problem_fields()), "pagelimit");
xassert(!$cf_nec->need_recheck());
xassert(!$cf_nec->run_attempted());

// option name containing parentheses
$options = $Conf->setting_json("options");
xassert(!array_filter((array) $options, function ($o) { return $o->id === 3; }));
$options[] = (object) ["id" => 3, "name" => "Supervisor(s)", "type" => "text", "position" => 3];
$Conf->save_setting("options", 1, json_encode($options));
$Conf->invalidate_caches(["options" => true]);

$ps->save_paper_json(json_decode("{\"id\":3,\"Supervisor(s)\":\"fart fart barf barf\"}"));
xassert_paper_status($ps);
$paper3 = $user_estrin->checked_paper_by_id(3);
xassert(!!$paper3->option(3));
xassert_eqq($paper3->option(3)->value, 1);
xassert_eqq($paper3->option(3)->data(), "fart fart barf barf");

$ps->save_paper_json(json_decode("{\"id\":3,\"Supervisor\":\"fart fart bark bark\"}"));
xassert_paper_status($ps);
$paper3 = $user_estrin->checked_paper_by_id(3);
xassert(!!$paper3->option(3));
xassert_eqq($paper3->option(3)->value, 1);
xassert_eqq($paper3->option(3)->data(), "fart fart bark bark");

$ps->save_paper_json(json_decode("{\"id\":3,\"Supervisors\":\"farm farm bark bark\"}"));
xassert_paper_status($ps);
$paper3 = $user_estrin->checked_paper_by_id(3);
xassert(!!$paper3->option(3));
xassert_eqq($paper3->option(3)->value, 1);
xassert_eqq($paper3->option(3)->data(), "farm farm bark bark");

// mail to authors does not include information that only reviewers can see
// (this matters when an author is also a reviewer)
MailChecker::clear();
save_review(14, $user_estrin, ["overAllMerit" => 5, "revexp" => 1, "papsum" => "Summary 1", "comaut" => "Comments 1", "ready" => false]);
save_review(14, $user_varghese, ["ovemer" => 5, "revexp" => 2, "papsum" => "Summary V", "comaut" => "Comments V", "compc" => "PC V", "ready" => true]);
$paper14 = $user_estrin->checked_paper_by_id(14);
HotCRPMailer::send_contacts("@rejectnotify", $paper14);
MailChecker::check_db("test05-reject14-1");
xassert_assign($Conf->root_user(), "action,paper,user\ncontact,14,varghese@ccrc.wustl.edu");
$paper14 = $user_estrin->checked_paper_by_id(14);
HotCRPMailer::send_contacts("@rejectnotify", $paper14);
MailChecker::check_db("test05-reject14-2");

ConfInvariants::test_all($Conf);

xassert_exit();
