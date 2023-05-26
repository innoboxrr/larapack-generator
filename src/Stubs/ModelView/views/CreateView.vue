<template>

	<div class="uk-section uk-section-xsmall">

		<!-- Header -->
		<div class="uk-container uk-container-expand">
			
			<h3 class="uk-heading-divider">

				<router-link :to="{name: 'AdminPluralPascalCaseModelName'}">
					
					<i class="fas fa-arrow-circle-left"></i>

				</router-link>

				Create
			
			</h3>

		</div>

		<div class="uk-section">
			
			<div class="uk-container uk-container-small ">
				
				<div class="uk-card uk-card-default uk-card-body">
					
					<create-form 
						@submit="formSubmit"/>

				</div>

			</div>

		</div>

	</div>

</template>

<script>

	import CreateForm from '@models/kebabcasemodelname/forms/CreateForm.vue'

	export default {

		components: {
			
			CreateForm

		},
		
		emits: ['updateData'],

		mounted(){

			this.fetchCreatePolicy();

		},

		data() {
		
			return {

				fetchCreatePolicyAttempts: 0,

			}
		
		},

		methods: {

			fetchCreatePolicy() {

				model.userCan('create')
					.then( res => {

                        this.fetchCreatePolicyAttempts = 0;

						if(!res.data.create) {

							// this.$router.push({name: "NotAuthorized" });
							
						}

                    })
                    .catch( error => {

                        if(this.fetchCreatePolicyAttempts <= 3) {

                            setTimeout( () => {

                                ++this.fetchCreatePolicyAttempts;

                                this.fetchCreatePolicy();

                            }, 1500);

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