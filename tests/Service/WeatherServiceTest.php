<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\WeatherService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;

final class WeatherServiceTest extends TestCase
{
    private function makeClient(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url) {
            if (str_starts_with($url, 'https://geocoding-api.open-meteo.com')) {
                return new MockResponse(json_encode([
                    'results' => [[
                        'name'      => 'Paris',
                        'latitude'  => 48.8566,
                        'longitude' => 2.3522,
                        'country'   => 'France',
                        'admin1'    => 'Île-de-France',
                    ]],
                ]), ['http_code' => 200]);
            }

            if (str_starts_with($url, 'https://api.open-meteo.com')) {
                $nowIso = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))
                    ->setTime((int) date('H'), 0)
                    ->format('Y-m-d\TH:00');

                // 4 heures synthétiques
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

                return new MockResponse(json_encode([
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
                ]), ['http_code' => 200]);
            }

            return new MockResponse('', ['http_code' => 404]);
        });
    }

    private function makeCache(): CacheInterface
    {
        // Suffisant pour des tests unitaires (implémente Contracts\CacheInterface)
        return new ArrayAdapter();
    }

    #[Test]
    public function testGeocodeCityReturnsFirstMatch(): void
    {
        $svc = new WeatherService($this->makeClient(), $this->makeCache());
        $res = $svc->geocodeCity('Paris');
        $this->assertIsArray($res);
        $this->assertSame('Paris', $res['name']);
        $this->assertSame('France', $res['country']);
    }

    #[Test]
    public function testGetForecastByCoordsBuildsCurrentHourlyAndDaily(): void
    {
        $svc = new WeatherService($this->makeClient(), $this->makeCache());
        $res = $svc->getForecastByCoords(48.8566, 2.3522, 'Europe/Paris', 4);
        $this->assertIsArray($res);
        $this->assertArrayHasKey('current', $res);
        $this->assertArrayHasKey('hourly', $res);
        $this->assertArrayHasKey('daily', $res);
        $this->assertNotEmpty($res['hourly']);
        $this->assertNotEmpty($res['daily']);
    }
}
