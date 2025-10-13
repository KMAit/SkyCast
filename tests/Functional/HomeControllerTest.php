<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Functional test for HomeController.
 * Uses the real WeatherService but stubs HTTP and cache dependencies.
 */
final class HomeControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('test', $_SERVER['APP_ENV'] ?? 'undefined', 'Kernel must run in test environment.');
    }

    #[Test]
    public function homepageRendersKpisWithStubbedDependencies(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();

        // --- Stub HTTP client with deterministic Open-Meteo responses ---
        $http = new MockHttpClient(function (string $method, string $url) {
            if (str_starts_with($url, 'https://geocoding-api.open-meteo.com')) {
                $geo = [
                    'results' => [[
                        'name'      => 'Paris',
                        'latitude'  => 48.8566,
                        'longitude' => 2.3522,
                        'country'   => 'France',
                        'admin1'    => 'Île-de-France',
                    ]],
                ];

                return new MockResponse(json_encode($geo), ['http_code' => 200]);
            }

            // Fake forecast data
            $nowIso = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))
                ->setTime((int) date('H'), 0)
                ->format('Y-m-d\TH:00');

            $times = [];
            $temps = [];
            $winds = [];
            $prec  = [];
            $codes = [];
            $hums  = [];
            $uvs   = [];

            for ($i = 0; $i < 4; ++$i) {
                $t       = (new \DateTimeImmutable($nowIso))->modify("+{$i} hour")->format('Y-m-d\TH:i');
                $times[] = $t;
                $temps[] = 16.0 + $i;
                $winds[] = 8    + $i;
                $prec[]  = 0.0;
                $codes[] = 2;
                $hums[]  = 60  + $i;
                $uvs[]   = 0.8 + $i * 0.1;
            }

            $dailyDate = (new \DateTimeImmutable($nowIso))->format('Y-m-d');

            $forecast = [
                'current_weather' => [
                    'temperature'   => 16.4,
                    'windspeed'     => 8.0,
                    'winddirection' => 135,
                    'is_day'        => 1,
                    'weathercode'   => 2,
                    'time'          => $nowIso,
                ],
                'hourly' => [
                    'time'                 => $times,
                    'temperature_2m'       => $temps,
                    'wind_speed_10m'       => $winds,
                    'precipitation'        => $prec,
                    'weathercode'          => $codes,
                    'relative_humidity_2m' => $hums,
                    'uv_index'             => $uvs,
                ],
                'daily' => [
                    'time'               => [$dailyDate, $dailyDate],
                    'temperature_2m_max' => [20.0, 19.0],
                    'temperature_2m_min' => [10.0, 9.0],
                    'precipitation_sum'  => [0.0, 1.2],
                    'weathercode'        => [2, 61],
                    'uv_index_max'       => [2.6, 1.9],
                ],
            ];

            return new MockResponse(json_encode($forecast), ['http_code' => 200]);
        });

        // --- Replace dependencies in the test container ---
        static::getContainer()->set(HttpClientInterface::class, $http);

        // Use an in-memory traceable cache to satisfy Symfony profiler
        $traceablePool = new TraceableAdapter(new ArrayAdapter());
        static::getContainer()->set('cache.app', $traceablePool);

        // --- Perform request ---
        $client->request('GET', '/?city=Paris');

        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        // --- Assert rendered KPI sections ---
        $this->assertStringContainsString('Données actuelles', $html);
        $this->assertStringContainsString('Température', $html);
        $this->assertStringContainsString('Vent', $html);
        $this->assertStringContainsString('Humidité', $html);
        $this->assertStringContainsString('Indice UV', $html);
        $this->assertStringContainsString('Prévision horaire', $html);
    }
}
