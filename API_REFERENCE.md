# 🌐 API Reference — SkyCast

SkyCast uses the **[Open-Meteo](https://open-meteo.com/)** public API to fetch weather data.

This document describes the external endpoints currently integrated into the project.

---

## 1. [Geocoding API](https://geocoding-api.open-meteo.com/v1/search) (city → coordinates)

**Endpoint:**

```bash
https://geocoding-api.open-meteo.com/v1/search
```

**Example:**

```bash
https://geocoding-api.open-meteo.com/v1/search?name=Paris&count=1&language=fr&format=json
```

**Sample response (simplified):**

```json
{
  "results": [
    {
      "name": "Paris",
      "latitude": 48.8534,
      "longitude": 2.3488,
      "country": "France",
      "admin1": "Île-de-France"
    }
  ]
}
```

---

## 2. [Forecast API](https://api.open-meteo.com/v1/forecast) (weather forecast)

**Endpoint:**

```bash
https://api.open-meteo.com/v1/forecast
```

**Example:**

```bash
https://api.open-meteo.com/v1/forecast?latitude=48.85&longitude=2.35&timezone=Europe/Paris&current_weather=true&hourly=temperature_2m,precipitation,wind_speed_10m
```

**Sample response (simplified):**

```json
{
  "latitude": 48.85,
  "longitude": 2.35,
  "current_weather": {
    "temperature": 22.3,
    "windspeed": 15.4,
    "winddirection": 250,
    "is_day": 1,
    "weathercode": 3,
    "time": "2025-09-18T15:00"
  },
  "hourly": {
    "time": ["2025-09-18T15:00", "2025-09-18T16:00"],
    "temperature_2m": [22.3, 21.9],
    "wind_speed_10m": [15.4, 12.7],
    "precipitation": [0.0, 0.1]
  }
}
```

---

## 3. Notes

- Reverse geocoding (/v1/reverse) is not yet available in Open-Meteo.
- For now, SkyCast displays latitude/longitude when using geolocation.
- A future enhancement could integrate [Nominatim](https://nominatim.org/)
