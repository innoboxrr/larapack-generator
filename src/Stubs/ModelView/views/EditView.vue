<template>

	<div class="uk-section uk-section-xsmall">
			
		<div class="uk-container uk-container-small ">
			
			<div class="uk-card uk-card-default uk-card-body">

				<edit-form 
					:key="$route.params.id"
					:kebabcasemodelname-id="$route.params.id"
					@submit="formSubmit"/>

			</div>

		</div>

	</div>

</template>

<script>

	import { getPolicy } from '@models/kebabcasemodelname'
	import EditForm from '@models/kebabcasemodelname/forms/EditForm.vue'

	export default {

		components: {
			
			EditForm

		},

		emits: ['updateData'],

		mounted() {

			this.fetchEditPolicy();

		},

		methods: {

			fetchEditPolicy() {

				getPolicy('update', this.$route.params.id).then( res => {

					if(!res.data.update) {

						// this.$router.push({name: "NotAuthorized" });
						
					}

                });

			},

			formSubmit(payload) {	

				this.$emit('updateData', payload);

				this.$router.push({

					name: "AdminShowPascalCaseModelName", 

					params: { 

						id: payload.data.id 

					} 

				});

			}

		}

	}

</script>