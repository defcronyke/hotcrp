<?php
// test04.php -- HotCRP user database tests
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

declare(strict_types=1);
global $Opt;
$Opt = [
    "contactdb_dsn" => "mysql://hotcrp_testdb:m5LuaN23j26g@localhost/hotcrp_testdb_cdb",
    "contactdb_passwordHmacKeyid" => "c1",
    "obsoletePasswordInterval" => 1
];
require_once(preg_replace('/\/test\/[^\/]+/', '/test/setup.php', __FILE__));

function password($email, $iscdb = false) {
    global $Conf;
    $dblink = $iscdb ? $Conf->contactdb() : $Conf->dblink;
    $result = Dbl::qe($dblink, "select password from ContactInfo where email=?", $email);
    $row = Dbl::fetch_first_row($result);
    return $row[0] ?? null;
}

function save_password($email, $encoded_password, $iscdb = false) {
    global $Conf;
    $dblink = $iscdb ? $Conf->contactdb() : $Conf->dblink;
    Dbl::qe($dblink, "update ContactInfo set password=?, passwordTime=?, passwordUseTime=? where email=?", $encoded_password, Conf::$now + 1, Conf::$now + 1, $email);
    Conf::advance_current_time(Conf::$now + 2);
}

if (!$Conf->contactdb()) {
    error_log("! Error: The test contactdb has not been initialized.");
    error_log("! You may need to run `lib/createdb.sh -c test/cdb-options.php --no-dbuser --batch`.");
    exit(1);
}

$user_chair = $Conf->checked_user_by_email("chair@_.com");
$marina = "marina@poema.ru";

user($marina)->change_password("rosdevitch");
xassert_eqq(password($marina), "");
xassert_neqq(password($marina, true), "");
xassert(user($marina)->check_password("rosdevitch"));
$Conf->qe("update ContactInfo set password=? where contactId=?", "rosdevitch", user($marina)->contactId);
xassert_neqq(password($marina), "");
xassert_neqq(password($marina, true), "");
xassert(user($marina)->check_password("rosdevitch"));

// different password in localdb => both passwords work
save_password($marina, "crapdevitch", false);
xassert(user($marina)->check_password("crapdevitch"));
xassert(user($marina)->check_password("rosdevitch"));

// change contactdb password => both passwords change
user($marina)->change_password("dungdevitch");
xassert(user($marina)->check_password("dungdevitch"));
xassert(!user($marina)->check_password("assdevitch"));
xassert(!user($marina)->check_password("rosdevitch"));
xassert(!user($marina)->check_password("crapdevitch"));

// update contactdb password => old local password useless
save_password($marina, "isdevitch", true);
xassert_eqq(password($marina), "");
xassert(user($marina)->check_password("isdevitch"));
xassert(!user($marina)->check_password("dungdevitch"));

// update local password only
save_password($marina, "ncurses", false);
xassert_eqq(password($marina), "ncurses");
xassert_neqq(password($marina, true), "ncurses");
xassert(user($marina)->check_password("ncurses"));

// logging in with global password makes local password obsolete
Conf::advance_current_time(Conf::$now + 3);
xassert(user($marina)->check_password("isdevitch"));
Conf::advance_current_time(Conf::$now + 3);
xassert(!user($marina)->check_password("ncurses"));

// null contactdb password => password is unset
save_password($marina, null, true);
$info = user($marina)->check_password_info("ncurses");
xassert(!$info["ok"]);
xassert($info["unset"] ?? null);

// restore to "this is a cdb password"
user($marina)->change_password("isdevitch");
xassert_eqq(password($marina), "");
save_password($marina, "isdevitch", true);
xassert_eqq(password($marina, true), "isdevitch");
// current status: local password is empty, global password "isdevitch"

// checking an unencrypted password encrypts it
$mu = user($marina);
xassert($mu->check_password("isdevitch"));
xassert_eqq(substr(password($marina, true), 0, 2), " \$");
xassert_eqq(password($marina), "");

// checking an encrypted password doesn't change it
save_password($marina, ' $$2y$10$/URgqlFgQHpfE6mg4NzJhOZbg9Cc2cng58pA4cikzRD9F0qIuygnm', true);
save_password($marina, '', false);
xassert(user($marina)->check_password("isdevitch"));
xassert_eqq(password($marina, true), ' $$2y$10$/URgqlFgQHpfE6mg4NzJhOZbg9Cc2cng58pA4cikzRD9F0qIuygnm');

// insert someone into the contactdb
$result = Dbl::qe($Conf->contactdb(), "insert into ContactInfo set firstName='Te', lastName='Thamrongrattanarit', email='te@_.com', affiliation='Brandeis University', collaborators='Computational Linguistics Magazine', password=' $$2y$10$/URgqlFgQHpfE6mg4NzJhOZbg9Cc2cng58pA4cikzRD9F0qIuygnm'");
assert(!Dbl::is_error($result));
Dbl::free($result);
xassert(!maybe_user("te@_.com"));
$u = $Conf->contactdb_user_by_email("te@_.com");
xassert(!!$u);
xassert_eqq($u->firstName, "Te");

// inserting them should succeed and borrow their data
$us = new UserStatus($Conf->root_user());
$acct = $us->save((object) ["email" => "te@_.com"]);
xassert(!!$acct);
$te = user("te@_.com");
xassert(!!$te);
xassert_eqq($te->firstName, "Te");
xassert_eqq($te->lastName, "Thamrongrattanarit");
xassert_eqq($te->affiliation, "Brandeis University");
xassert($te->check_password("isdevitch"));
xassert_eqq($te->collaborators(), "Computational Linguistics Magazine");

// changing email should work too, but not change cdb except for defaults
$result = Dbl::qe($Conf->contactdb(), "insert into ContactInfo set firstName='', lastName='Thamrongrattanarit 2', email='te2@_.com', affiliation='Brandeis University or something', collaborators='Newsweek Magazine', password=' $$2y$10$/URgqlFgQHpfE6mg4NzJhOZbg9Cc2cng58pA4cikzRD9F0qIuygnm'");
xassert(!Dbl::is_error($result));
Dbl::free($result);
$te->change_email("te2@_.com");
$te = maybe_user("te@_.com");
$te2 = user("te2@_.com");
xassert(!$te);
xassert(!!$te2);
xassert_eqq($te2->lastName, "Thamrongrattanarit");
xassert_eqq($te2->affiliation, "Brandeis University");
$acct = $us->save((object) ["lastName" => "Thamrongrattanarit 1", "firstName" => "Te 1"], $te2);
xassert(!!$acct);
$te2 = user("te2@_.com");
xassert_eqq($te2->lastName, "Thamrongrattanarit 1");
xassert_eqq($te2->affiliation, "Brandeis University");
$te2_cdb = $te2->contactdb_user();
xassert(!!$te2_cdb);
xassert_eqq($te2_cdb->email, "te2@_.com");
xassert_eqq($te2_cdb->affiliation, "Brandeis University or something");
// site contact updates keep old value in cdb
xassert_eqq($te2_cdb->firstName, "");
xassert_eqq($te2_cdb->lastName, "Thamrongrattanarit 2");

// simplify whitespace
$acct = $us->save((object) ["lastName" => " Thamrongrattanarit  1  \t", "firstName" => "Te  1", "affiliation" => "  Brandeis   Friendiversity"], $te2);
xassert(!!$acct);
$te2 = user("te2@_.com");
xassert_eqq($te2->firstName, "Te 1");
xassert_eqq($te2->lastName, "Thamrongrattanarit 1");
xassert_eqq($te2->affiliation, "Brandeis Friendiversity");

// changes by the chair don't affect the cdb
Contact::set_guser(user($marina));
$te2_cdb = $te2->contactdb_user();
Dbl::qe($Conf->contactdb(), "update ContactInfo set affiliation='' where email='te2@_.com'");
$acct = $us->save((object) ["firstName" => "Wacky", "affiliation" => "String"], $te2);
xassert(!!$acct);
$te2 = user("te2@_.com");
xassert(!!$te2);
xassert_eqq($te2->firstName, "Wacky");
xassert_eqq($te2->lastName, "Thamrongrattanarit 1");
xassert_eqq($te2->affiliation, "String");
$te2_cdb = $te2->contactdb_user();
xassert(!!$te2_cdb);
xassert_eqq($te2_cdb->firstName, "");
xassert_eqq($te2_cdb->lastName, "Thamrongrattanarit 2");
xassert_eqq($te2_cdb->affiliation, "String");

// borrow from cdb
$acct = $us->save((object) ["email" => "te@_.com"]);
xassert(!!$acct);
$te = user("te@_.com");
xassert_eqq($te->email, "te@_.com");
xassert_eqq($te->firstName, "Te");
xassert_eqq($te->lastName, "Thamrongrattanarit");
xassert_eqq($te->affiliation, "Brandeis University");
xassert_eqq($te->collaborators(), "Computational Linguistics Magazine");

// create a user in cdb: create, then delete from local db
$anna = "akhmatova@poema.ru";
xassert(!maybe_user($anna));
$acct = $us->save((object) ["email" => $anna, "first" => "Anna", "last" => "Akhmatova"]);
xassert(!!$acct);
Dbl::qe("delete from ContactInfo where email=?", $anna);
Dbl::qe($Conf->contactdb(), "update ContactInfo set passwordUseTime=1 where email=?", $anna);
save_password($anna, "aquablouse", true);
xassert(!maybe_user($anna));
MailChecker::check0();

$user_estrin = user("estrin@usc.edu");
$user_floyd = user("floyd@EE.lbl.gov");
$user_van = user("van@ee.lbl.gov");

$ps = new PaperStatus($Conf);
$ps->save_paper_json((object) [
    "id" => 1,
    "authors" => ["puneet@catarina.usc.edu", $user_estrin->email,
                  $user_floyd->email, $user_van->email, $anna]
]);

$paper1 = $user_chair->checked_paper_by_id(1);
$user_anna = user($anna);
xassert(!!$user_anna);
xassert($user_anna->act_author_view($paper1));
xassert($user_estrin->act_author_view($paper1));
xassert($user_floyd->act_author_view($paper1));
xassert($user_van->act_author_view($paper1));

// user merging
$us->save((object) ["email" => "anne1@_.com", "tags" => ["a#1"], "roles" => (object) ["pc" => true]]);
$us->save((object) ["email" => "anne2@_.com", "first" => "Anne", "last" => "Dudfield", "data" => (object) ["data_test" => 139], "tags" => ["a#2", "b#3"], "roles" => (object) ["sysadmin" => true], "collaborators" => "derpo\n"]);
$user_anne1 = user("anne1@_.com");
$a1id = $user_anne1->contactId;
xassert_eqq($user_anne1->firstName, "");
xassert_eqq($user_anne1->lastName, "");
xassert_eqq($user_anne1->collaborators(), "");
xassert_eqq($user_anne1->tag_value("a"), 1.0);
xassert_eqq($user_anne1->tag_value("b"), null);
xassert_eqq($user_anne1->roles, Contact::ROLE_PC);
xassert_eqq($user_anne1->data("data_test"), null);
xassert_eqq($user_anne1->email, "anne1@_.com");
xassert_assign($user_anne1, "paper,tag\n1,~butt#1\n2,~butt#2");
$user_anne2 = user("anne2@_.com");
$a2id = $user_anne2->contactId;
xassert_eqq($user_anne2->firstName, "Anne");
xassert_eqq($user_anne2->lastName, "Dudfield");
xassert_eqq($user_anne2->collaborators(), "All (derpo)");
xassert_eqq($user_anne2->tag_value("a"), 2.0);
xassert_eqq($user_anne2->tag_value("b"), 3.0);
xassert_eqq($user_anne2->roles, Contact::ROLE_ADMIN);
xassert_eqq($user_anne2->data("data_test"), 139);
xassert_eqq($user_anne2->email, "anne2@_.com");
xassert_assign($user_anne2, "paper,tag\n2,~butt#3\n3,~butt#4");
xassert_assign($user_chair, "paper,action,user\n1,conflict,anne2@_.com");
xassert($user_anne1 && $user_anne2);
$paper1 = $Conf->checked_paper_by_id(1);
xassert_eqq($paper1->tag_value("{$a1id}~butt"), 1.0);
xassert_eqq($paper1->tag_value("{$a2id}~butt"), null);
$paper2 = $Conf->checked_paper_by_id(2);
xassert_eqq($paper2->tag_value("{$a1id}~butt"), 2.0);
xassert_eqq($paper2->tag_value("{$a2id}~butt"), 3.0);
$paper3 = $Conf->checked_paper_by_id(3);
xassert_eqq($paper3->tag_value("{$a1id}~butt"), null);
xassert_eqq($paper3->tag_value("{$a2id}~butt"), 4.0);
$merger = new MergeContacts($user_anne2, $user_anne1);
xassert($merger->run());
$user_anne1 = user("anne1@_.com");
$user_anne2 = maybe_user("anne2@_.com");
xassert($user_anne1 && !$user_anne2);
xassert_eqq($user_anne1->firstName, "Anne");
xassert_eqq($user_anne1->lastName, "Dudfield");
xassert_eqq($user_anne1->collaborators(), "All (derpo)");
xassert_eqq($user_anne1->tag_value("a"), 1.0);
xassert_eqq($user_anne1->tag_value("b"), 3.0);
xassert_eqq($user_anne1->roles, Contact::ROLE_PC | Contact::ROLE_ADMIN);
xassert_eqq($user_anne1->data("data_test"), 139);
xassert_eqq($user_anne1->email, "anne1@_.com");
$paper1 = $Conf->checked_paper_by_id(1);
xassert($paper1->has_conflict($user_anne1));
xassert_eqq($paper1->tag_value("{$a2id}~butt"), null);
xassert_eqq($paper1->tag_value("{$a1id}~butt"), 1.0);
$paper2 = $Conf->checked_paper_by_id(2);
xassert_eqq($paper2->tag_value("{$a2id}~butt"), null);
xassert_eqq($paper2->tag_value("{$a1id}~butt"), 2.0);
$paper3 = $Conf->checked_paper_by_id(3);
xassert_eqq($paper3->tag_value("{$a2id}~butt"), null);
xassert_eqq($paper3->tag_value("{$a1id}~butt"), 4.0);

// different forms of profile saving
$us->save((object) ["email" => "anne1@_.com", "roles" => "pc"]);
$user_anne1 = user("anne1@_.com");
xassert_eqq($user_anne1->roles, Contact::ROLE_PC);

$us->save((object) ["email" => "anne1@_.com", "roles" => ["pc", "sysadmin"]]);
$user_anne1 = user("anne1@_.com");
xassert_eqq($user_anne1->roles, Contact::ROLE_PC | Contact::ROLE_ADMIN);

$us->save((object) ["email" => "anne1@_.com", "roles" => "chair, sysadmin"]);
$user_anne1 = user("anne1@_.com");
xassert_eqq($user_anne1->roles, Contact::ROLE_PC | Contact::ROLE_CHAIR | Contact::ROLE_ADMIN);

$us->save((object) ["email" => "anne1@_.com", "roles" => "-chair"]);
$user_anne1 = user("anne1@_.com");
xassert_eqq($user_anne1->roles, Contact::ROLE_PC | Contact::ROLE_ADMIN);

$us->save((object) ["email" => "anne1@_.com", "roles" => "-sysadmin"]);
$user_anne1 = user("anne1@_.com");
xassert_eqq($user_anne1->roles, Contact::ROLE_PC);

$us->save((object) ["email" => "anne1@_.com", "roles" => "+chair"]);
$user_anne1 = user("anne1@_.com");
xassert_eqq($user_anne1->roles, Contact::ROLE_PC | Contact::ROLE_CHAIR);

// creation interactions
Dbl::qe($Conf->dblink, "insert into ContactInfo (email, password) values ('betty2@_.com','')");
Dbl::qe($Conf->contactdb(), "insert into ContactInfo (email, password, firstName, lastName) values ('betty3@_.com','','Betty','Shabazz'), ('betty4@_.com','','Betty','Kelly'),('betty5@_.com','','Betty','Davis'),
    ('cengiz@isi.edu','TEST PASSWORD','','')");
$u = Contact::create($Conf, null, ["email" => "betty1@_.com", "name" => "Betty Grable"]);
xassert_eqq($u->firstName, "Betty");
xassert_eqq($u->lastName, "Grable");
$u = $Conf->contactdb_user_by_email("betty1@_.com");
xassert_eqq($u->firstName, "Betty");
xassert_eqq($u->lastName, "Grable");
$u = Contact::create($Conf, null, ["email" => "betty2@_.com", "name" => "Betty Apiafi"]);
xassert_eqq($u->firstName, "Betty");
xassert_eqq($u->lastName, "Apiafi");
$u = $Conf->contactdb_user_by_email("betty2@_.com");
xassert_eqq($u->firstName, "Betty");
xassert_eqq($u->lastName, "Apiafi");
$u = Contact::create($Conf, null, ["email" => "betty3@_.com", "name" => "Betty Crocker"]);
xassert_eqq($u->firstName, "Betty");
xassert_eqq($u->lastName, "Shabazz");
$u = $Conf->contactdb_user_by_email("betty3@_.com");
xassert_eqq($u->firstName, "Betty");
xassert_eqq($u->lastName, "Shabazz");
$u = Contact::create($Conf, null, ["email" => "betty4@_.com", "name" => "Betty Crocker", "affiliation" => "France"]);
xassert_eqq($u->firstName, "Betty");
xassert_eqq($u->lastName, "Kelly");
xassert_eqq($u->affiliation, "France");
$u = $Conf->contactdb_user_by_email("betty4@_.com");
xassert_eqq($u->firstName, "Betty");
xassert_eqq($u->lastName, "Kelly");
xassert_eqq($u->affiliation, "France");
$u = $Conf->user_by_email("betty5@_.com");
xassert(!$u);
$u = $Conf->contactdb_user_by_email("betty5@_.com");
$u->activate_database_account();
$u = $Conf->checked_user_by_email("betty5@_.com");
xassert($u->has_account_here());
xassert_eqq($u->firstName, "Betty");
xassert_eqq($u->lastName, "Davis");

$u = Contact::create($Conf, null, ["email" => "cengiz@isi.edu"]);
xassert($u->contactId > 0);
xassert_eqq($u->firstName, "Cengiz");
xassert_eqq($u->contactdb_user()->firstName, "Cengiz");
xassert_eqq($u->lastName, "Alaettinoğlu");
xassert_eqq($u->contactdb_user()->lastName, "Alaettinoğlu");

// contactdb_update
Dbl::qe($Conf->dblink, "insert into ContactInfo set email='betty6@_.com', password='Fart', firstName='Betty', lastName='Knowles'");
$u = $Conf->checked_user_by_email("betty6@_.com");
xassert(!!$u);
xassert(!$u->contactdb_user());
$u->contactdb_update();
$v = $u->contactdb_user();
xassert(!!$v);
xassert_eqq($v->firstName, "Betty");
xassert_eqq($v->lastName, "Knowles");

// PC json
$pc_json = $Conf->hotcrp_pc_json($user_chair);
xassert_eqq($pc_json[$pc_json["__order__"][0]]->email, "anne1@_.com");
$Conf->sort_by_last = true;
$Conf->invalidate_caches(["pc" => true]);
$pc_json = $Conf->hotcrp_pc_json($user_chair);
xassert_eqq($pc_json[$pc_json["__order__"][0]]->email, "mgbaker@cs.stanford.edu");
xassert_eqq($pc_json["12"]->email, "mgbaker@cs.stanford.edu");
xassert_eqq($pc_json["12"]->lastpos, 5);
xassert_eqq($pc_json["21"]->email, "vera@bombay.com");
xassert_eqq($pc_json["21"]->lastpos, 5);

xassert_exit();
