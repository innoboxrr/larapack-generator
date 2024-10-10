<template>	
	<form id="camelCaseModelNameFilterForm" @submit.prevent="onSubmit">
		<div class="uk-flex uk-flex-left uk-child-width-1-4@m uk-child-width-1-1@s" uk-grid>
			<div>
				<text-input-component
					:custom-class="inputClass"
					type="text"
					name="id"
					label="ID"
					placeholder="ID" 
					v-model="id" />
			</div>

			<!-- Add more inputs -->

		</div>

		<div class="uk-flex uk-flex-right uk-child-width-auto@m uk-child-width-1-1@m" uk-grid>
			<div>
				<button :class="buttonClass">
					{{ __('Search') }}
				</button>
			</div>
			<div>
				<button 
					:class="buttonClass + ' bg-gray-400'"
					@click.prevent="resetForm()">
					{{ __('Reset') }}
				</button>
			</div>
		</div>
	</form>
</template>

<script>
	
	import { 
		TextInputComponent,
		//import_more_components//
	} from 'innoboxrr-form-elements'

	export default {

		components: {
			TextInputComponent,
			//register_more_components//
		},

		emits: ['submit'],

		data() {
			return {
				id: null,
				//add_more_data//
			}

		},

		methods: {

			onSubmit() {
				this.$emit('submit', this.$data);
			},

			resetForm() {
				this.id = null;
				//reset_inputs//
				this.onSubmit();
			}
		}
	}
</script>