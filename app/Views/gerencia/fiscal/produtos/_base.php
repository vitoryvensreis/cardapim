<?php
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$url=$base.'/fiscal';
$token=static function() use($e,$csrf):void { ?><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><?php };
?>
