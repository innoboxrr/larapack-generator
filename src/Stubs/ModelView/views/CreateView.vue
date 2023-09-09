<template>

	<div class="view-section uk-section uk-section-xsmall">
			
		<div class="view-container uk-container uk-container-small">
			
			<div class="uk-card uk-card-default uk-card-body">
				
				<create-form 
					@submit="formSubmit"/>

			</div>

		</div>

	</div>

</template>

<script>

	import { getPolicy } from '@models/kebabcasemodelname'
	import CreateForm from '@models/kebabcasemodelname/forms/CreateForm.vue'

	export default {

		components: {
			
			CreateForm

		},
		
		emits: ['updateData'],

		mounted(){

			this.fetchCreatePolicy();

		},

		methods: {

			fetchCreatePolicy() {

				getPolicy('create').then( res => {

					if(!res.data.create) {

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