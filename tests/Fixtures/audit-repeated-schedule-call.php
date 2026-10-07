<?php
declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../examples/laravel/bootstrap/app.php';
$kernel=$app->make(Kernel::class);
$kernel->bootstrap();
for($i=1;$i<=2;$i++) {
    try {
        $exit=$kernel->call('schedule:run');
        echo "attempt=$i returned=$exit\n";
    } catch(Throwable $e) {
        echo "attempt=$i exception=".$e::class."\n";
    }
    $journal=getenv('SCHEDULER_JOURNAL');
    echo "attempt=$i journal=".(is_file($journal)?count(file($journal)):'absent')."\n";
}
