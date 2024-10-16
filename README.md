
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

## Comandos Disponibles

### JSON Importer

Este comando permite importar modelos y migraciones desde un archivo JSON. Ejemplo de uso:

```
php builder json:importer /path/to/file.json
```

### Otros Comandos

```
make:app-service-provider        - Crea un proveedor de servicio de aplicación.
make:auth-service-provider       - Crea un proveedor de servicio de autenticación.
make:config                      - Crea un archivo de configuración.
make:controller                  - Crea un nuevo controlador.
make:event-service-provider      - Crea un proveedor de servicio de eventos.
make:events                      - Crea eventos y listeners para el modelo.
make:excel                       - Crea una clase de Excel.
make:export                      - Crea una clase de exportación.
make:export-notification         - Crea una clase de notificación de exportación.
make:factory                     - Crea una nueva fábrica.
make:filters                     - Crea una clase de filtros.
make:full-model                  - Crea un entorno completo de modelo.
make:migration                   - Crea una nueva migración.
make:model                       - Crea un nuevo modelo.
make:model-traits                - Crea traits para el modelo.
make:model-view                  - Crea la sección de administración en Vue.
make:observer                    - Crea un observer.
make:policy                      - Crea una nueva política.
make:providers                   - Crea todos los proveedores de servicio.
make:requests                    - Crea una clase de requests.
make:resource                    - Crea una nueva clase de recurso.
make:route                       - Crea una nueva ruta.
make:route-service-provider       - Crea un proveedor de servicio de rutas.
make:test                        - Crea una nueva clase de test.

remove:full-model                - Elimina todas las entidades relacionadas con un modelo.
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
            "relations": [
                {
                    "type": "belongsTo",
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
