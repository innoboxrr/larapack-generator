# __COMPOSER_NAME__

__DESCRIPTION__

## Instalación

```
composer require __COMPOSER_NAME__
```

Laravel descubre el paquete solo: sus proveedores están declarados en
`extra.laravel.providers`.

La aplicación que lo instala tiene que cumplir lo que el código generado da por
hecho: Sanctum, un usuario `Notifiable`, `JsonResource::withoutWrapping()` y, si
usa las exportaciones, `maatwebsite/excel`. La lista completa y el porqué están
en la guía de LaraPack, `.claude/skills/larapack/SKILL.md`.

## Desarrollo

La arquitectura de este paquete la genera
[LaraPack](https://github.com/innoboxrr/larapack-generator) a partir de
`laraimport.json`. Modelos, controladores, requests, políticas, migraciones y
vistas no se escriben a mano: se declaran y se generan.

```
composer install
php vendor/bin/builder larapack:schema        # el contrato
php vendor/bin/builder larapack:validate      # valida laraimport.json
php vendor/bin/builder larapack:import --vue  # genera
php vendor/bin/builder larapack:verify        # comprueba que nada se salió
vendor/bin/phpunit
```

## Publicación

La versión la declara el archivo `VERSION`. Al empujar a la rama principal corren
los tests y, si pasan, se etiqueta esa versión. Para publicar una nueva, sube
`VERSION` y anota el cambio en `CHANGELOG.md`.
