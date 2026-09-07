<template>

    <div class="flex justify-center items-center">
        <div class="max-w-2xl w-full">
            <div class="card bg-white dark:bg-slate-600 border rounded-lg px-8 pt-6 pb-8 mb-4 dark:border-slate-800">

                <EditForm
                    :key="route.params.id"
                    :kebabcasemodelname-id="route.params.id"
                    @submit="onUpdated" />

            </div>
        </div>
    </div>

</template>

<script setup>

    import { onMounted } from 'vue'
    import { useRoute, useRouter } from 'vue-router'

    import EditForm from '../forms/EditForm.vue'
    import { getPolicy } from '../index'

    const route = useRoute()
    const router = useRouter()

    const emit = defineEmits(['updateData'])

    onMounted(async () => {

        const policy = await getPolicy('update', route.params.id)

        if (! policy.update) {
            // router.push({ name: 'NotAuthorized' })
        }

    })

    const onUpdated = (camelCaseModelName) => {

        emit('updateData', camelCaseModelName)

        router.push({
            name: 'AdminShowPascalCaseModelName',
            params: { id: camelCaseModelName.id },
        })

    }

</script>
