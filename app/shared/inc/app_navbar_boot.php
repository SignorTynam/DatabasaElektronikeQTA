<?php
declare(strict_types=1);

/**
 * app_navbar_boot.php — vendos KUR të vizatohet navbar-i.
 *
 * Disa faqe e përfshijnë navbar-in para se të dalë <head>/<body> (praktikë e
 * vjetër që prodhonte HTML përpara doctype-it), të tjerat brenda <body>.
 * Ky skedar i mbulon të dyja: nëse koka ende s'është nxjerrë, navbar-i shtyhet
 * dhe vizatohet nga app_head.php menjëherë pas <body>; përndryshe vizatohet aty
 * për aty.
 */

if (!empty($GLOBALS['QTA_HEAD_RENDERED'])) {
  require __DIR__ . '/app_navbar.php';
} else {
  $GLOBALS['QTA_NAV_DEFERRED'] = true;
}
