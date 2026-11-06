<?php
declare(strict_types=1);
final class Money {
 public static function cents(string $amount): int {
  if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D',$amount)) throw new InvalidArgumentException('Enter a positive amount with at most two decimal places.');
  [$whole,$fraction]=array_pad(explode('.',$amount),2,'');
  return (int)$whole*100+(int)str_pad($fraction,2,'0');
 }
 public static function decimal(int $cents): string { return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT); }
 public static function levy(int $cents,int $basisPoints): int {
  if ($basisPoints<0 || $basisPoints>10000) throw new InvalidArgumentException('Invalid levy rate.');
  // Split first to avoid overflowing 64-bit integers at DECIMAL(15,2) limits.
  return intdiv($cents,10000)*$basisPoints+intdiv(($cents%10000)*$basisPoints+5000,10000);
 }
 public static function display(string $amount): string { [$n,$f]=explode('.',self::decimal(self::cents($amount))); return '₦'.preg_replace('/\B(?=(\d{3})+(?!\d))/',',',$n).'.'.$f; }
}
