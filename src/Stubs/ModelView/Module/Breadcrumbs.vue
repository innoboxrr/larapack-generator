<template>

    <nav :class="classFor('breadcrumb')" aria-label="Breadcrumb">

        <template v-for="(page, index) in pages" :key="page.link ?? page.title">

            <span v-if="index > 0" :class="classFor('breadcrumbSeparator')" aria-hidden="true">/</span>

            <!-- El ultimo es donde esta el usuario: no es un enlace, y se
                 anuncia como la pagina actual. -->
            <span
                v-if="index === pages.length - 1"
                :class="classFor('breadcrumbCurrent')"
                aria-current="page">{{ page.title }}</span>

            <RouterLink
                v-else
                :to="page.link"
                :class="classFor('breadcrumbLink')">{{ page.title }}</RouterLink>

        </template>

    </nav>

</template>

<script setup>

    /**
     * Las migas de pan del modulo.
     *
     * Existe aqui, dentro del modulo generado, y no como componente global de
     * la aplicacion: hasta ahora las vistas Vue usaban un
     * <BreadcrumbsComponent> que no estaba definido en ningun paquete del
     * ecosistema, asi que el codigo generado solo compilaba si la aplicacion
     * anfitriona lo habia registrado por su cuenta, sin que ningun import ni
     * peerDependency lo dijera.
     */

    import { RouterLink } from 'vue-router'
    import { classFor } from 'innoboxrr-form-core'

    defineProps({
        /** @type {{ link: string, title: string }[]} */
        pages: {
            type: Array,
            required: true,
        },
    })

</script>
