<?php

namespace Innoboxrr\LarapackGenerator\Tests\EndToEnd\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * El usuario de la aplicación anfitriona.
 *
 * Deliberadamente no es App\Models\User: un paquete generado se instala en
 * aplicaciones cuyo modelo de usuario no conoce, y tiene que funcionar igual.
 * El ecosistema decide quién administra con isAdmin(); aquí lo hace quien tiene
 * un correo del dominio admin.test.
 *
 * Notifiable no es un capricho del fixture: el usuario de Laravel lo trae, y
 * el paquete avisa por notificación cuando termina una exportación.
 */
class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    public function isAdmin(): bool
    {
        return str_ends_with((string) $this->email, '@admin.test');
    }
}
