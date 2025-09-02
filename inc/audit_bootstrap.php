<?php
// inc/audit_bootstrap.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

function qta_audit_attach(PDO $pdo): void {
  $uid = $_SESSION['user_id'] ?? null;                       // mjafton ID-ja
  $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
  $ua  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250);
  $stmt = $pdo->prepare("SET @audit_user_id=:uid, @audit_ip=:ip, @audit_ua=:ua");
  $stmt->execute([':uid'=>$uid, ':ip'=>$ip, ':ua'=>$ua]);
}
