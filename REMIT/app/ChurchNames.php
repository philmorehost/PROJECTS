<?php
declare(strict_types=1);
/** Consistent UTF-8 CAPS without requiring mbstring on cPanel. */
final class ChurchNames
{
    public static function upper(string $name): string
    {
        static $map=null;
        $map ??= json_decode(file_get_contents(__DIR__.'/unicode-uppercase.json'),true,512,JSON_THROW_ON_ERROR);
        return strtoupper(strtr($name,$map));
    }
    public static function normalize(mixed $name): string
    {
        if(!is_string($name) || preg_match('//u',$name)!==1 || preg_match('/[\x00-\x1f\x7f]/',$name))throw new InvalidArgumentException('Enter a valid church name.');
        $name=self::upper(trim(preg_replace('/\s+/u',' ',$name)));
        if($name==='' || strlen($name)>150 || !preg_match('/\p{L}/u',$name))throw new InvalidArgumentException('Church names must contain letters and be at most 150 bytes after conversion to CAPS.');
        return $name;
    }
    public static function labels(array $unit): array
    {
        foreach(['node_name','parent_name','zone_name','area_name'] as $field)if(is_string($unit[$field] ?? null))$unit[$field]=self::upper($unit[$field]);
        return $unit;
    }
}
