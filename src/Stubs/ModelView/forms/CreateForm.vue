<template>
	
	<form :id="formId" @submit.prevent="onSubmit">      

<!-- Add more inputs -->

        <button-component
            :custom-class="buttonClass"
            :disabled="disabled"
            value="Crear" />
        
    </form>

</template>

<script>

    import { createModel } from '@models/kebabcasemodelname'
    import JSValidator from 'innoboxrr-js-validator'
    import {
        TextInputComponent,
        ButtonComponent,
//import_more_components//
    } from 'innoboxrr-form-elements'
	
	export default {

        components: {
            TextInputComponent,
            ButtonComponent,
//register_more_components//
        },

        props: {
        	formId: {
        		type: String,
        		default: 'createPascalCaseModelNameForm',
        	}
//props//
        },

        emits: ['submit'],

        data() {
            return {
                disabled: false,
                JSValidator: undefined,
//add_more_data//
            }
        },

        mounted() {
            this.fetchData();
            this.JSValidator = new JSValidator(this.formId).init();
        },

        methods: {

            fetchData() {},

            onSubmit() {
                if(this.JSValidator.status) {
                    this.disabled = true;
                    createModel({
//submit_data//
                    }).then( res => {
                        this.$emit('submit', res);
                        setTimeout(() => { this.disabled = false; }, 2500);
                    }).catch(error => {
                        this.disabled = false;
                        if(error.response.status == 422)
                            this.JSValidator
                                .appendExternalErrors(error.response.data.errors);
                    });
                } else {
                    this.disabled = false;
                }
            }
        }
	}
</script>