# 🌤️ SkyCast

SkyCast is a simple weather application built with **Symfony 6.4**.
It allows users to quickly check the weather for a city or use **geolocation** to get forecasts for their current position.

## 🚀 Features

- Search by city (via Open-Meteo Geocoding API)
- Detect current location ("Autour de moi")
- Display of current conditions (temperature, wind, precipitation)
- Hourly forecast (12 hours)
- Clean UI with cards and SVG icons
- _Alpha_ status (portfolio project)

## 🛠️ Tech Stack

- **Backend**: Symfony 6.4, HttpClient
- **Frontend**: Twig, custom CSS, vanilla JS
- **Quality**: Husky + lint-staged, PHP-CS-Fixer, TwigCS
- **External API**: [Open-Meteo](https://open-meteo.com/)

## 📦 Installation

1. Clone the project:

```bash
git clone https://github.com/<your-username>/skycast.git
cd skycast
```

2. Install dependencies:

```bash
composer install
npm install
```

3. Start the Symfony server:

```bash
symfony serve -d
```

4. Run the asset watcher (if using AssetMapper):

```bash
npm run dev
```

▶️ Usage

- Open [http://127.0.0.1:8000](http://127.0.0.1:8000)
- Enter a city or click Autour de moi to use geolocation.

📸 Screenshots

(placeholders to be replaced with real captures)

- Homepage with search
- Weather results (cards)
- Hourly forecast

📖 API Documentation

See [API_REFERENCE.md](./API_REFERENCE.md)

🗂️ Notes

Project is still in progress (alpha).
Internal roadmap is not included in this file.
