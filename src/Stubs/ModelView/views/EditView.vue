<template>

	<div class="uk-section uk-section-xsmall">

		<!-- Header -->
		<div class="uk-container uk-container-expand">
			
			<h3 class="uk-heading-divider">

				<router-link :to="{name: 'AdminPluralPascalCaseModelName'}">
					
					<i class="fas fa-arrow-circle-left"></i>

				</router-link>

				Edit
			
			</h3>

		</div>

		<div class="uk-section">
			
			<div class="uk-container uk-container-small ">
				
				<div class="uk-card uk-card-default uk-card-body">

					<edit-form 
						:key="$route.params.id"
						:kebabcasemodelname-id="$route.params.id"
						@submit="formSubmit"/>

				</div>

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

		data() {
		
			return {

				fetchEditPolicyAttempts: 0,

			}
		
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

						id: payload.res.data.id 

					} 

				});

			}

		}

	}

</script>