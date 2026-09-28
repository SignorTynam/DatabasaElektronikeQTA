<?php
declare(strict_types=1);

/**
 * Datat e shkruara në formularë duhet të kenë të njëjtin interpretim në çdo rrjedhë.
 */

require_once __DIR__ . '/../../app/shared/domain.php';

t_case('Datat e formularëve: formatet e mbështetura', function () {
  t_eq('1990-03-05', qta_parse_date_input('05.03.1990'), 'pranon formatin e ndërfaqes me pika');
  t_eq('1990-03-05', qta_parse_date_input('05-03-1990'), 'pranon formatin me viza');
  t_eq('1990-03-05', qta_parse_date_input('05/03/1990'), 'pranon formatin me pjerrëta');
  t_eq('1990-03-05', qta_parse_date_input('1990-03-05'), 'pranon formatin ISO');
});

t_case('Datat e formularëve: vlerat e pavlefshme', function () {
  t_eq(null, qta_parse_date_input('31.02.1990'), 'refuzon ditën që nuk ekziston');
  t_eq(null, qta_parse_date_input('05.13.1990'), 'refuzon muajin që nuk ekziston');
  t_eq(null, qta_parse_date_input('1990/03/05'), 'refuzon ISO me ndarës tjetër');
  t_eq(null, qta_parse_date_input(''), 'vlera bosh mbetet bosh');
});
