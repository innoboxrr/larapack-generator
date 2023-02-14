# Copiar CLI Builder

Si tras la instalación no se añade el instalador en la raíz del directorio ejecute el comando

cp vendor/<vendor>/<package>/builder.example builder

# REQUERIMIENTOS

 1. El paquete supone que el proyecto de laravel tiene por lo menos el modelo App\Models\User
 2. Se recomienda tener configurado AWS S3 para el proceso de exportación de archivos. En caso contrario modificar el parámetro de configuración export_disk

# INSTALACIÓN

 - Ejecute el comando: **composer require innoboxrr/larapack-generator**
 - Verifique que el modelo App\Models\User, tiene el método isAdmin(), el cual en caso de no tener un sistema basado en roles debe retornar false, o en su defecto realizar una validación como:
     public function isAdmin()
     {
     	return $this->id === 1;
     }


