
# Larapack Generator

## Apoya Nuestro Trabajo 🙌

Desarrollamos estos paquetes para la comunidad de Laravel con el objetivo de hacer la vida de los desarrolladores más fácil. Si te sientes agradecido por nuestro trabajo y te gustaría apoyarnos, considera inscribirte en uno de nuestros cursos de pago. Nos ayudas a seguir manteniendo estos recursos y mejoras tus habilidades.

Te recomendamos especialmente el curso [Desarrollo de Paquetes en Laravel](https://laravelers.com/course/275). Aprenderás a crear tus propios paquetes de Laravel y PHP, optimizando tu productividad como desarrollador.

Gracias por tu apoyo, ¡apreciamos enormemente a nuestra comunidad!

## Instalación

Para instalar el paquete, ejecuta el siguiente comando:

```
composer require innoboxrr/larapack-generator
```

Si tras la instalación no se añade el instalador en la raíz del directorio, ejecuta el siguiente comando:

```
cp vendor/<vendor>/<package>/builder.example builder
```

## Requerimientos

| Requisito | Versión |
| --------- | ------- |
| PHP | ^8.3 |
| Laravel | ^13.0 |
| Symfony Console | ^7.4 \|\| ^8.0 |
| PHPUnit (dev) | ^12.0 \|\| ^13.0 |
| Orchestra Testbench (dev) | ^11.0 |

1. El paquete supone que el proyecto Laravel tiene por lo menos el modelo `App\Models\User`.
2. Se recomienda tener configurado AWS S3 para la exportación de archivos. Si no, modifica el parámetro de configuración `export_disk`.
3. Verifica que el modelo `App\Models\User` tenga el método `isAdmin()`. Si no tienes un sistema de roles, puedes implementar el siguiente método básico:

```php
public function isAdmin()
{
    return $this->id === 1;
}
```

4. La estructura principal del paquete debe estar dentro del directorio `src` para proyectos de paquetes y `app` para aplicaciones Laravel.

## Código generado (Laravel 13)

Los stubs producen código alineado con las convenciones de Laravel 11+/13:

- **Modelos**: los casts se declaran con el método `casts(): array`, y el observer,
  la política y la factoría se enlazan con los atributos `#[ObservedBy]`,
  `#[UsePolicy]` y `#[UseFactory]` en lugar de descubrirse por reflexión.
- **Controladores**: implementan `Illuminate\Routing\Controllers\HasMiddleware`
  con un método estático `middleware()`, en vez de `$this->middleware()` en el
  constructor. El controlador base ya no extiende `Illuminate\Routing\Controller`.
- **Rutas**: usan callables `[FooController::class, 'accion']`, por lo que el
  `RouteServiceProvider` ya no declara un namespace de controladores.
- **Proveedores**: extienden `Illuminate\Support\ServiceProvider` directamente;
  ya no se usan las clases base de `Illuminate\Foundation\Support\Providers`.
  El `EventServiceProvider` sólo descubre eventos y listeners.
- **Migraciones, requests, políticas y recursos**: firmas con tipos de retorno
  (`up(): void`, `rules(): array`, `toArray(Request $request): array`, …).
- **`phpunit.xml`**: esquema de PHPUnit 12/13 con `cacheDirectory` y las
  variables de entorno de Laravel 13 (`CACHE_STORE`, `APP_MAINTENANCE_DRIVER`).

## Comandos Disponibles

Los comandos viven en el espacio `larapack:` y estan disponibles de dos formas:

```
php artisan larapack:full-model Post      # dentro de una app Laravel
php builder larapack:full-model Post      # binario, tambien fuera de Laravel
```

El binario mantiene los nombres antiguos (`make:*`, `json:importer`) como alias.
En Artisan no se registran porque `make:model`, `make:policy`, `make:factory` y
`make:observer` son comandos del propio Laravel.

### Importador JSON

Genera todo el entorno de varios modelos a partir de un archivo declarativo:

```
php artisan larapack:import /ruta/al/laraimport.json
```

Sin ruta, busca `laraimport.json` en la raiz del proyecto.

### Resto de comandos

```
larapack:app-service-provider    - Crea un proveedor de servicio de aplicacion.
larapack:auth-service-provider   - Crea un proveedor de servicio de autenticacion.
larapack:config                  - Crea un archivo de configuracion.
larapack:controller              - Crea un nuevo controlador.
larapack:event-service-provider  - Crea un proveedor de servicio de eventos.
larapack:events                  - Crea eventos y listeners para el modelo.
larapack:excel                   - Crea una clase de Excel.
larapack:export                  - Crea una clase de exportacion.
larapack:export-notification     - Crea una clase de notificacion de exportacion.
larapack:factory                 - Crea una nueva fabrica.
larapack:filters                 - Crea una clase de filtros.
larapack:full-model              - Crea un entorno completo de modelo.
larapack:migration               - Crea una nueva migracion.
larapack:model                   - Crea un nuevo modelo.
larapack:model-traits            - Crea traits para el modelo.
larapack:model-view              - Crea la seccion de administracion en Vue.
larapack:observer                - Crea un observer.
larapack:policy                  - Crea una nueva politica.
larapack:providers               - Crea todos los proveedores de servicio.
larapack:requests                - Crea una clase de requests.
larapack:resource                - Crea una nueva clase de recurso.
larapack:route                   - Crea una nueva ruta.
larapack:route-service-provider  - Crea un proveedor de servicio de rutas.
larapack:test                    - Crea una nueva clase de test.

larapack:remove-full-model       - Elimina todas las entidades de un modelo.
```

## Ejemplo de JSON de Importación 

```json
{
    "models": [
        {
            "name": "Post",
            "props": [
                {
                    "name": "title",
                    "type": "string",
                    "constraint": null,
                    "default": null,
                    "nullable": false,
                    "fillable": true,
                    "creatable": true,
                    "updatable": true,
                    "exports_cols": true,
                    "cast": null,
                    "form": true,
                    "form_component": "TextInputComponent",
                    "form_submit": true,
                    "enum": {
                        "op1": "Option 1",
                        "op2": "Option 2"
                    },
                    "datatable": true
                },
                {
                    "name": "payload",
                    "type": "longText",
                    "constraint": null,
                    "default": null,
                    "nullable": true,
                    "fillable": true,
                    "creatable": true,
                    "updatable": true,
                    "exports_cols": true,
                    "cast": "json",
                    "form": false,
                    "form_component": null,
                    "form_submit": false,
                    "enum": {
                        "op1": "Option 1",
                        "op2": "Option 2"
                    },
                    "datatable": true
                },
                {
                    "name": "user_id",
                    "type": "foreignId",
                    "constraint": "users",
                    "default": null,
                    "nullable": false,
                    "fillable": true,
                    "creatable": true,
                    "updatable": true,
                    "exports_cols": true,
                    "cast": null,
                    "form": false,
                    "form_component": null,
                    "form_submit": true,
                    "enum": {
                        "op1": "Option 1",
                        "op2": "Option 2"
                    },
                    "datatable": true
                }
            ],
            "metas": true,
            "load_relations": [
                {
                    "type": "belongsTo",
                    "namespace": "App\\Models",
                    "related": "User",
                    "name": "user"
                }
            ],
            "editable_metas": [],
            "assignments": [],
            "filters": [
                {
                    "name": "Title",
                    "mode": "like"
                }
            ],
            "requests": [
                {
                    "name": "Create",
                    "rules": {
                        "title": "required|string|max:255",
                        "payload": "nullable",
                        "user_id": "required|exists:users,id"
                    }
                },
                {
                    "name": "Update",
                    "rules": {
                        "title": "nullable|string|max:255",
                        "payload": "nullable",
                        "user_id": "nullable|exists:users,id"
                    }
                }
            ],
            "load_counts": []
        }
    ],
    "pivots": [
        {
            "name": "role_user",
            "props": [
                {
                    "name": "role_id",
                    "type": "foreignId",
                    "constraint": "roles",
                    "default": null,
                    "nullable": false
                },
                {
                    "name": "user_id",
                    "type": "foreignId",
                    "constraint": "users",
                    "default": null,
                    "nullable": false
                }
            ]
        }
    ]
}
```

## Notas Importantes

- Para el empleo de este paquete en el desarrollo de paquetes, se espera que el directorio que contenga la lógica principal tenga el nombre "src". En una aplicación de Laravel, la carpeta predeterminada es "app".
