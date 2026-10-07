import { ApiError } from './client'
import { formErrors } from './formErrors'

describe('formErrors', () => {
  it('puts field messages under the form fields', () => {
    const error = new ApiError({
      status: 422,
      code: 'validation_failed',
      message: 'Check the form.',
      errors: { login: ['Enter your email.'], password: ['Too short.', 'Needs a digit.'] },
    })
    expect(formErrors(error, ['login', 'password'])).toEqual({
      fields: { login: 'Enter your email.', password: 'Too short.' },
      form: null,
    })
  })

  it('maps API fields to form fields through aliases', () => {
    const error = new ApiError({ status: 422, code: 'validation_failed', message: 'x', errors: { phone: ['Taken.'] } })
    expect(formErrors(error, ['contact'], { phone: 'contact' }).fields).toEqual({ contact: 'Taken.' })
  })

  it('shows errors for fields the form does not have in the alert', () => {
    const error = new ApiError({ status: 422, code: 'invalid_code', message: 'x', errors: { challenge_id: ['Code expired.'] } })
    expect(formErrors(error, ['code'])).toEqual({ fields: {}, form: 'Code expired.' })
  })

  it('uses the message when there are no field errors, never the code', () => {
    const error = new ApiError({ status: 423, code: 'locked', message: 'Try again in 15 minutes.' })
    expect(formErrors(error, ['login'])).toEqual({ fields: {}, form: 'Try again in 15 minutes.' })
  })

  it('returns nothing without an error', () => {
    expect(formErrors(null, ['login'])).toEqual({ fields: {}, form: null })
  })
})
