<template>

    <div v-if="camelCaseModelName">

        <Breadcrumbs :pages="breadcrumbs" />

        <div class="uk-container uk-container-expand">
            <div class="uk-grid-small" uk-grid>

                <div class="uk-width-1-3@m uk-width-1-1@s">
                    <ModelCard :kebabcasemodelname="camelCaseModelName" />
                </div>

                <div class="uk-width-expand uk-width-1-2@m uk-width-1-1@s">

                    <div v-if="isShowView">
                        <ModelProfile :kebabcasemodelname="camelCaseModelName" />
                    </div>

                    <div v-else>
                        <RouterView @update-data="load" />
                    </div>

                </div>

            </div>
        </div>

    </div>

</template>

<script setup>

    import { computed, onMounted, watch } from 'vue'
    import { RouterView, useRoute, useRouter } from 'vue-router'

    import Breadcrumbs from '../../../components/Breadcrumbs.vue'
    import t from 'innoboxrr-i18n'

    import ModelCard from '../widgets/ModelCard.vue'
    import ModelProfile from '../widgets/ModelProfile.vue'
    import { usePascalCaseModelNameStore } from '../store'

    const route = useRoute()
    const router = useRouter()

    const store = usePascalCaseModelNameStore()

    const camelCaseModelName = computed(() => store.current)

    const isShowView = computed(() => route.name === 'AdminShowPascalCaseModelName')

    const load = async () => {

        const loaded = await store.fetchOne(route.params.id)

        document.title = loaded?.name ?? 'PascalCaseModelName'

    }

    onMounted(load)

    // Navegar de un registro a otro sin desmontar la vista tiene que recargar.
    watch(() => route.params.id, (id) => id && load())

    const breadcrumbs = computed(() => {

        const pages = [
            {
                link: router.resolve({ name: 'AdminPluralPascalCaseModelName' }).fullPath,
                title: 'PluralPascalCaseModelName',
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
            title: camelCaseModelName.value.name ?? 'PascalCaseModelName',
        })

        if (route.name === 'AdminEditPascalCaseModelName') {
            pages.push({
                link: router.resolve({
                    name: 'AdminEditPascalCaseModelName',
                    params: { id: camelCaseModelName.value.id },
                }).fullPath,
                title: t('Edit'),
            })
        }

        return pages

    })

</script>
