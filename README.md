# Pretty Weather

Pretty Weather is a module for **Joomla 5.2 or newer** that displays weather-based content. You define content (and optional conditional content blocks) that can include live temperature placeholders, and the module fetches the current weather for a location from your chosen provider.

## Features

- Multiple weather providers (see below)
- Placeholders for live values: `{temp}`, `{feels_like}`, `{temp_min}`, `{temp_max}`, `{name}`
- Conditional content blocks driven by temperature rules (e.g. show a block only when `temp` < 5)
- Configurable units: Standard (Kelvin), Metric (Celsius), Imperial (Fahrenheit)
- Built-in caching with a configurable minimum time between API calls
- Debug mode that displays the latest raw data and any API errors
- English and Dutch translations

## Providers

The module supports three providers. They all return the same set of values, so your content and rules work the same regardless of which one you pick.

| Provider | API key | Cost | True daily min/max | Location name | Notes |
|---|---|---|---|---|---|
| **OpenWeatherMap** (current weather) | Required | Free tier (commercial use allowed) | No | From API | Default. `{temp_min}`/`{temp_max}` are left empty — for a single point they equal the current temperature. |
| **OpenWeatherMap One Call API 4.0** | Required | "One Call by Call" subscription (billing required); first 1,000 calls/day free | Yes | Manual | Makes **two** calls per refresh (current + 1-day forecast). Raise "Time between API calls" to stay within the free allowance. |
| **Open-Meteo** | Not needed | Free (non-commercial) | Yes | Manual | Keyless and easy to set up. Commercial sites should review Open-Meteo's licensing. Kelvin is derived by converting Celsius. |

**Which should I choose?**

- **Open-Meteo** — quickest start (no key) and gives genuine daily min/max. Best for non-commercial sites.
- **OpenWeatherMap (current weather)** — commercial-friendly free tier and an automatic location name, but min/max are not meaningful.
- **OpenWeatherMap One Call API 4.0** — when you need true daily min/max on a commercial site and are comfortable with the billing-enabled subscription.

Providers that do not return a location name (One Call API 4.0 and Open-Meteo) use the optional **Location name** field to fill the `{name}` placeholder.

## Installation

Install the module ZIP through **System → Install → Extensions** in the Joomla administrator. Updates are delivered automatically through the built-in Joomla update server.

## Configuration

In the module settings:

1. **Provider** — choose your weather provider.
2. **Provider API Key** — required for the two OpenWeatherMap providers (shown only for those).
3. **Units** — Standard (Kelvin), Metric (Celsius) or Imperial (Fahrenheit).
4. **Latitude / Longitude** — the location to fetch weather for (dot notation, e.g. `50.997873` / `5.870567`).
5. **Location name** *(optional)* — overrides/fills the `{name}` placeholder; needed to show a name with One Call API 4.0 or Open-Meteo.
6. **Time between API calls** — minimum minutes before the data is refreshed (caching).
7. **Debug** — show the latest raw values and API errors in the frontend while troubleshooting.

### Content & placeholders

Add your **Default content** and any number of **Conditional content** blocks. Within either, use:

- `{temp}` — current temperature
- `{feels_like}` — feels-like temperature
- `{temp_min}` — daily minimum temperature
- `{temp_max}` — daily maximum temperature
- `{name}` — location name

> When the basic **OpenWeatherMap** provider is used, `{temp_min}` and `{temp_max}` are intentionally left empty because they mirror the current temperature. Conditional rules using minimum/maximum temperature still work but behave like the current temperature. Use **One Call API 4.0** or **Open-Meteo** for true daily extremes.

### Conditional content

Each conditional block has one or more rules (type + operator + value) combined with AND logic. Types: `temp`, `feels_like`, `temp_min`, `temp_max`. Operators: greater than, greater than or equal, less than, less than or equal, equal, not equal.

## Links

- [Joomla Extensions Directory listing](https://extensions.joomla.org/extension/pretty-weather/)
- [GitHub project](https://github.com/TLWebdesignNL/Pretty-Weather)
- [Report an issue / request a provider](https://github.com/TLWebdesignNL/Pretty-Weather/issues)

## License

GNU General Public License version 2 or later; see `LICENSE.txt`.
