<?php
declare(strict_types=1);
namespace SchedulerTest\Tests\Integration;
final class AuditRepeatedScheduleCallTest extends DemoProcessTestCase
{
    public function testBrokenScheduleMustStillFailClosedOnSecondProgrammaticCall(): void
    {
        $pid=$this->startPhpScript(dirname(__DIR__).'/Fixtures/audit-repeated-schedule-call.php', ['DEMO_INCLUDE_BROKEN_TASK'=>'1','DEMO_SLOW_SECONDS'=>'0']);
        self::assertSame(0,$this->finish($pid));
        $output=$this->stdoutOf($pid);
        self::assertStringContainsString('attempt=1 exception=SchedulerTest\\Contract\\Exception\\InvalidCronExpressionException',$output);
        self::assertStringContainsString('attempt=1 journal=absent',$output);
        self::assertStringContainsString('attempt=2 exception=SchedulerTest\\Contract\\Exception\\InvalidCronExpressionException',$output,'Repeated resolution must not reuse a partially registered native Schedule');
        self::assertSame([],$this->journal(),'A broken schedule must never execute a partial task set');
    }
}
