# custom/DisplaFruit — módulo interno de DisplaFruit Signage

Código personalizado de DisplaFruit, autocargado por PSR-4 (`Xibo\Custom\DisplaFruit\` → este
directorio, ver `composer.json`). No requiere editar el core de Xibo.

## Estructura

```
custom/
├── settings-custom.php                      # registra el middleware en $middleware
└── DisplaFruit/
    ├── Middleware/DisplaFruitMiddleware.php  # registra rutas + controladores (DI) + feature
    ├── Controller/DashboardController.php     # GET /displafruit/dashboard
    ├── Controller/PublishController.php        # POST /displafruit/publish-all (web + API)
    └── views/displafruit-dashboard.twig        # vista del panel del operador
```

## Cómo se engancha sin tocar el core

1. `custom/settings-custom.php` añade el middleware a `$middleware` (lo lee `ConfigService`).
2. `Xibo\Middleware\State::setMiddleWare()` llama a `setApp()` y `addRoutes()` sobre cada
   middleware, **antes** de cargar `lib/routes*.php`.
3. En `addRoutes()`:
   - Se registran los controladores en el contenedor PHP-DI (`$container->set(...)`),
     replicando el patrón de `lib/Dependencies/Controllers.php`
     (`useBaseDependenciesService($c->get('ControllerBaseDependenciesService'))`).
   - Se registran las rutas según el entrypoint (`web` vs `api`), protegidas por `FeatureAuth`.

## Rutas

| Método | Ruta | Entrypoint | Auth | Feature |
|---|---|---|---|---|
| GET | `/displafruit/dashboard` | web | sesión | `displafruit.operator` / `displays.view` |
| POST | `/displafruit/publish-all` | web | sesión + CSRF | `library.add` |
| POST | `/api/displafruit/publish-all` | api | OAuth2 | `library.add` |

Ver `README_DISPLAFRUIT.md` (raíz) para uso, branding, rol y conexión de pantallas, y
`CHANGELOG_DISPLAFRUIT.md` para el detalle de cambios respecto a upstream.
