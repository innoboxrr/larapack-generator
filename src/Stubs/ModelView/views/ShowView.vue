<template>

    <div>

        <Breadcrumbs :pages="breadcrumbs" />

        <div class="fe-container-wide">

            <!-- Mientras llega el registro, su forma. Antes la vista se quedaba
                 en blanco, o enseñaba el registro anterior. -->
            <div v-if="! camelCaseModelName" class="fe-split" aria-busy="true">
                <SkeletonComponent shape="block" height="16rem" />
                <SkeletonComponent :lines="6" />
            </div>

            <div v-else class="fe-split">

                <div>
                    <ModelCard :kebabcasemodelname="camelCaseModelName" />
                </div>

                <div>
                    <ModelProfile :kebabcasemodelname="camelCaseModelName" />
                </div>

            </div>

        </div>

        <!-- @larapack:if update -->
        <!-- La edición se abre encima de la ficha, que sigue a la vista. -->
        <DrawerComponent
            :open="isEditing"
            :title="t('Edit :name', { name: t('SingularModelLabel') })"
            :close-label="t('Close')"
            @update:open="(open) => open || backToRecord()">
            <RouterView @update-data="onUpdated" />
        </DrawerComponent>
        <!-- @larapack:endif -->

    </div>

</template>

<script setup>

    import { computed, onMounted, watch } from 'vue'
    import { RouterView, useRoute, useRouter } from 'vue-router'
    import t from 'innoboxrr-i18n'
    import { SkeletonComponent } from 'innoboxrr-form-elements'
    // @larapack:if update
    import { DrawerComponent } from 'innoboxrr-form-elements'
    import { notifySuccess } from 'innoboxrr-form-core'
    // @larapack:endif

    import Breadcrumbs from '../../../components/Breadcrumbs.vue'

    import ModelCard from '../widgets/ModelCard.vue'
    import ModelProfile from '../widgets/ModelProfile.vue'
    import { usePascalCaseModelNameStore } from '../store'

    const route = useRoute()
    const router = useRouter()

    const store = usePascalCaseModelNameStore()

    // Solo el registro de la ruta: al pasar de uno a otro, el anterior no se
    // queda en pantalla mientras llega el nuevo.
    const camelCaseModelName = computed(() => (
        String(store.current?.id) === String(route.params.id) ? store.current : null
    ))

    // @larapack:if update
    const isEditing = computed(() => route.name === 'AdminEditPascalCaseModelName')

    // @larapack:endif
    const load = async () => {

        const loaded = await store.fetchOne(route.params.id)

        document.title = loaded?.displayPropName ?? t('SingularModelLabel')

    }

    onMounted(load)

    // Navegar de un registro a otro sin desmontar la vista tiene que recargar.
    watch(() => route.params.id, (id) => id && load())

    // @larapack:if update
    const backToRecord = () => router.push({
        name: 'AdminShowPascalCaseModelName',
        params: { id: route.params.id },
    })

    const onUpdated = () => {

        notifySuccess(t('Changes saved'))

        backToRecord()

        load()

    }

    // @larapack:endif
    const breadcrumbs = computed(() => {

        const pages = [
            {
                link: router.resolve({ name: 'AdminPluralPascalCaseModelName' }).fullPath,
                title: t('PluralModelLabel'),
            },
        ]

        if (! camelCaseModelName.value) {
            return pages
        }

        pages.push({
            link: router.resolve({
                name: 'AdminShowPascalCaseModelName',
                params: { id: camelCaseModelName.value.id },
            }).fullPath,
            title: camelCaseModelName.value.displayPropName ?? t('SingularModelLabel'),
        })

        // @larapack:if update
        if (route.name === 'AdminEditPascalCaseModelName') {
            pages.push({
                link: router.resolve({
                    name: 'AdminEditPascalCaseModelName',
                    params: { id: camelCaseModelName.value.id },
                }).fullPath,
                title: t('Edit'),
            })
        }

        // @larapack:endif
        return pages

    })

</script>
