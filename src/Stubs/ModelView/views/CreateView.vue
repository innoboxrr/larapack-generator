<template>

    <div>

        <Breadcrumbs :pages="breadcrumbs" />

        <div class="flex justify-center items-center mt-8">
            <div class="max-w-2xl w-full">
                <div class="card bg-white dark:bg-slate-600 border rounded-lg px-8 pt-6 pb-8 mb-4 dark:border-slate-800">

                    <h2 class="text-4xl font-bold dark:text-white mb-6">
                        {{ t('Create PluralPascalCaseModelName') }}
                    </h2>

                    <CreateForm @submit="onCreated" />

                </div>
            </div>
        </div>

    </div>

</template>

<script setup>

    import { computed, onMounted } from 'vue'
    import { useRouter } from 'vue-router'

    import Breadcrumbs from '../../../components/Breadcrumbs.vue'
    import t from 'innoboxrr-i18n'

    import CreateForm from '../forms/CreateForm.vue'
    import { getPolicy } from '../index'

    const router = useRouter()

    const emit = defineEmits(['updateData'])

    const breadcrumbs = computed(() => [
        {
            link: router.resolve({ name: 'AdminPluralPascalCaseModelName' }).fullPath,
            title: 'PluralPascalCaseModelName',
        },
        {
            link: router.resolve({ name: 'AdminCreatePascalCaseModelName' }).fullPath,
            title: t('Create PluralPascalCaseModelName'),
        },
    ])

    onMounted(async () => {

        const policy = await getPolicy('create')

        if (! policy.create) {
            // router.push({ name: 'NotAuthorized' })
        }

    })

    const onCreated = (camelCaseModelName) => {

        emit('updateData', camelCaseModelName)

        router.push({
            name: 'AdminShowPascalCaseModelName',
            params: { id: camelCaseModelName.id },
        })

    }

</script>
