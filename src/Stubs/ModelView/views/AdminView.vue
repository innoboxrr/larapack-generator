<template>

    <div id="AdminPluralPascalCaseModelNameWrapper">

        <div v-if="isIndex" class="fe-section-sm">

            <Breadcrumbs :pages="breadcrumbs" />

            <DataTable
                :show-title="false"
                :hide-columns="hideColumns"
                :key="crudKey" />

        </div>

        <div v-else>
            <RouterView @update-data="refresh" />
        </div>

    </div>

</template>

<script setup>

    import { computed, ref } from 'vue'
    import { RouterView, useRoute, useRouter } from 'vue-router'

    import DataTable from '../widgets/DataTable.vue'

    import Breadcrumbs from '../../../components/Breadcrumbs.vue'

    const route = useRoute()
    const router = useRouter()

    // Forzar el remontaje del datatable es la forma de recargarlo tras una
    // alta o una baja hecha en una vista hija.
    const crudKey = ref(0)

    const refresh = () => crudKey.value++

    const isIndex = computed(() => route.name === 'AdminPluralPascalCaseModelName')

    const hideColumns = computed(() => [])

    const breadcrumbs = computed(() => [
        {
            link: router.resolve({ name: 'AdminPluralPascalCaseModelName' }).fullPath,
            title: 'PluralPascalCaseModelName',
        },
    ])

</script>
