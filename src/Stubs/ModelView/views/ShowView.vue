<template>

	<div class="uk-section uk-section-xsmall">

		<!-- Header -->
		<div v-if="dataLoaded" class="uk-container uk-container-expand">

			<h3 class="uk-heading-divider">

				<router-link :to="{name: 'AdminPluralPascalCaseModelName'}">
					
					<i class="fas fa-arrow-circle-left"></i>

				</router-link>

				ID: {{ camelCaseModelName.id }}
			
			</h3>

		</div>

		<!-- Body -->
		<div v-if="dataLoaded" class="uk-section uk-section-small">
			
			<!-- Add specific view content -->

		</div>

	</div>

</template>

<script>

	import { showModel } from '@models/kebabcasemodelname'

	export default {

		mounted() {

			this.fetchData();

		},

		data() {
		
			return {

				dataLoaded: false,

				title: undefined,

				camelCaseModelNameId: this.$route.params.id,

				camelCaseModelName: {},

				fetchPascalCaseModelNameAttempt: 0

			}
		
		},

		methods: {

			fetchData() {

				this.fetchPascalCaseModelName().then( res => {

					this.dataLoaded = true;
					
					this.title = this.camelCaseModelName.name;

					document.title = this.title;

				});

			},

			fetchPascalCaseModelName() {

                showModel(this.camelCaseModelNameId).then( res => {

                    this.fetchPascalCaseModelNameAttempts;

                    this.camelCaseModelName = res.data;

                }).catch( error => {

                    if(this.fetchPascalCaseModelNameAttempts <= 3) {

                        setTimeout( () => {

                            ++this.fetchPascalCaseModelNameAttempts;

                            this.fetchPascalCaseModelName();

                        }, 1500);

                    }

                });

            },

		}

	}

</script>