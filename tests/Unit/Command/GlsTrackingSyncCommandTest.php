<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Command;

use CodeConjure\SyliusGlsPlugin\Address\StreetSplitter;
use CodeConjure\SyliusGlsPlugin\Api\ApiAccess;
use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
use CodeConjure\SyliusGlsPlugin\Command\GlsTrackingSyncCommand;
use CodeConjure\SyliusGlsPlugin\Eligibility\EligibilityChecker;
use CodeConjure\SyliusGlsPlugin\Parcel\GlsParcelPayloadFactory;
use CodeConjure\SyliusGlsPlugin\Parcel\GlsParcelService;
use CodeConjure\SyliusGlsPlugin\Pickup\DeliveryPointCatalog;
use CodeConjure\SyliusGlsPlugin\Repository\GlsParcelRepository;
use CodeConjure\SyliusGlsPlugin\Tests\Support\GlsParcelWorkflow;
use CodeConjure\SyliusGlsPlugin\Tests\Support\RecordingLogger;
use CodeConjure\SyliusGlsPlugin\Tests\Support\RecordingStateMachine;
use CodeConjure\SyliusGlsPlugin\Tracking\GlsTrackingSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;

final class GlsTrackingSyncCommandTest extends TestCase
{
    /** API nélküli módban a cron-futás sikeres, és kimondja, miért nem kérdezett. */
    public function testWithoutTheApiTheRunSucceedsAndSaysWhy(): void
    {
        $factory = new Psr17Factory();
        $access = new ApiAccess(new Psr18Client(new MockHttpClient(), $factory, $factory), $factory, $factory, null, null, null, null, 'HU', 'Sylius');
        $repository = $this->createStub(GlsParcelRepository::class);
        $repository->method('findForTracking')->willReturn([]);
        $em = $this->createStub(EntityManagerInterface::class);
        $workflow = GlsParcelWorkflow::create();
        $catalog = new DeliveryPointCatalog(new MockHttpClient(), new ArrayAdapter(), new RecordingLogger());
        $parcels = new GlsParcelService($access, new GlsParcelPayloadFactory(new StreetSplitter(), $catalog, new CodPolicy(), [], false, false), new EligibilityChecker(new CodPolicy()), $repository, $em, $workflow, new RecordingStateMachine(), 'A4_2x2');

        $tester = new CommandTester(new GlsTrackingSyncCommand(new GlsTrackingSyncService($access, $repository, $em, $workflow, $parcels, new RecordingLogger())));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('not configured', $tester->getDisplay());
        self::assertStringContainsString('found:', $tester->getDisplay());
        self::assertSame('gls:tracking:sync', GlsTrackingSyncCommand::NAME);
    }
}
