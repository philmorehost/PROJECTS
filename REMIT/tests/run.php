<?php
declare(strict_types=1);
require __DIR__.'/../app/Money.php';
$checks=0;
function same(mixed $actual,mixed $expected,string $label): void {global $checks;$checks++;if($actual!==$expected)throw new RuntimeException($label.': expected '.var_export($expected,true).' got '.var_export($actual,true));}
foreach(['0'=>0,'1'=>100,'0.01'=>1,'123.4'=>12340,'9999999999999.99'=>999999999999999] as $input=>$expected) same(Money::cents((string)$input),$expected,'parse '.$input);
foreach(['-1','1.001','1e3','NaN','','1,000','10000000000000',' 1','1.','1.00x'] as $bad) {
 $rejected=false;try{Money::cents($bad);}catch(InvalidArgumentException){$rejected=true;}same($rejected,true,'reject '.$bad);
}
same(Money::levy(100005,1000),10001,'half-up rounding');
same(Money::levy(999999999999999,10000),999999999999999,'large amount no overflow');
same(Money::levy(100,0),0,'zero levy');
same(Money::decimal(1),'0.01','decimal');same(Money::display('1234567.89'),'₦1,234,567.89','format');
for($i=0;$i<1000;$i++) { $c=random_int(0,999999999999999);same(Money::cents(Money::decimal($c)),$c,'roundtrip'); }
echo "PASS: $checks money assertions\n";
