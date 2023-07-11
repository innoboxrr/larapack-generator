# Laravel Auth Package

## Apoya Nuestro Trabajo 🙌

Desarrollamos estos paquetes de software de manera gratuita con la intención de contribuir a la comunidad de Laravel y facilitar la vida de los desarrolladores. Nos apasiona compartir lo que hemos aprendido y ver cómo nuestros paquetes ayudan a las personas en sus proyectos.

Sin embargo, también requerimos de apoyo para seguir creando y manteniendo estos recursos. Si estás en la posición de poder hacerlo, te invitamos a inscribirte a uno de nuestros cursos de pago. No solo estarías apoyando nuestro trabajo, sino que también podrías adquirir nuevas habilidades y conocimientos.

En particular, te recomendamos nuestro curso [Desarrollo de paquetes en Laravel para mejorar tu productividad](https://laravelers.com/course/275). Este curso está diseñado para enseñarte a desarrollar tus propios paquetes de Laravel y PHP. Al inscribirte, no solo estarás apoyando nuestro trabajo, sino que también estarás invirtiendo en tu propio crecimiento y desarrollo como programador.

Gracias por considerar esta opción y por tu apoyo continuo a nuestra labor. ¡Apreciamos enormemente a nuestra comunidad!

# Copiar CLI Builder

Si tras la instalación no se añade el instalador en la raíz del directorio ejecute el comando

cp vendor/<vendor>/<package>/builder.example builder

# REQUERIMIENTOS

 1. El paquete supone que el proyecto de laravel tiene por lo menos el modelo App\Models\User
 2. Se recomienda tener configurado AWS S3 para el proceso de exportación de archivos. En caso contrario modificar el parámetro de configuración export_disk
 3. Ejecute el comando: **composer require innoboxrr/larapack-generator**
 4. Verifique que el modelo App\Models\User, tiene el método isAdmin(), el cual en caso de no tener un sistema basado en roles debe retornar false, o en su defecto realizar una validación como:

```php 
public function isAdmin()
{
	return $this->id === 1;
}
```

# Importante
 -  Para el empleo de este paquete en el desarrollo de paquetes, se espera que el directorio que contenga la lógica principal del paquete tenga el nombre "src", en caso de una aplicación de laravel, la carpeta predetemrinada es "app"


