
import { useState } from 'react'
import api from './api'
import './auth.css'

function Auth({ onLogin }) {
  const [mode, setMode] = useState('login')
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  async function handleSubmit(event) {
    event.preventDefault()
    setError('')
    setLoading(true)

    try {
      const endpoint = mode === 'register' ? '/register' : '/login'

      const payload =
        mode === 'register'
          ? {
              name,
              email,
              password,
              password_confirmation: passwordConfirmation,
            }
          : { email, password }

      const response = await api.post(endpoint, payload)
      onLogin(response.data.user, response.data.token)
    } catch (err) {
      const errors = err.response?.data?.errors
      const firstError = errors ? Object.values(errors)[0]?.[0] : null

      setError(
        firstError ||
          err.response?.data?.message ||
          (!err.response
            ? `Cannot reach the OrgLife API at ${api.defaults.baseURL}. Start Laravel with "php artisan serve --host 127.0.0.1 --port 8000".`
            : 'The request failed. Please try again.'),
      )
    } finally {
      setLoading(false)
    }
  }

  return (
    <main className="auth-page">
      <section className="auth-intro">
        <div className="auth-brand">
          <div className="auth-logo">O</div>
          <span>OrgLife</span>
        </div>

        <div className="auth-message">
          <span className="eyebrow">YOUR CAMPUS, YOUR COMMUNITY</span>
          <h1>Find your people. Discover your purpose.</h1>
          <p>
            Connect with student organizations, discover new interests,
            and become part of your campus community.
          </p>
          <div className="auth-points">
            <p>✓ Discover student organizations</p>
            <p>✓ Connect with like-minded students</p>
            <p>✓ Join communities that inspire you</p>
          </div>
        </div>

        <p className="auth-footer">
          OrgLife · Student Organization Management System
        </p>
      </section>

      <section className="auth-panel">
        <div className="auth-form-wrap">
          <span className="eyebrow">
            {mode === 'login' ? 'WELCOME BACK' : 'JOIN THE COMMUNITY'}
          </span>

          <h2>
            {mode === 'login' ? 'Sign in to OrgLife' : 'Create your account'}
          </h2>

          <p className="auth-description">
            {mode === 'login'
              ? 'Enter your details to continue.'
              : 'Register to discover and join organizations.'}
          </p>

          <div className="auth-tabs">
            <button
              type="button"
              className={mode === 'login' ? 'selected' : ''}
              onClick={() => {
                setMode('login')
                setError('')
              }}
            >
              Log in
            </button>
            <button
              type="button"
              className={mode === 'register' ? 'selected' : ''}
              onClick={() => {
                setMode('register')
                setError('')
              }}
            >
              Sign up
            </button>
          </div>

          <form onSubmit={handleSubmit}>
            {mode === 'register' && (
              <label>
                Full name
                <input
                  type="text"
                  placeholder="Enter your full name"
                  value={name}
                  onChange={(event) => setName(event.target.value)}
                  required
                  maxLength={255}
                  autoComplete="name"
                />
              </label>
            )}

            <label>
              Email address
              <input
                type="email"
                placeholder="you@example.com"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                required
                autoComplete="email"
              />
            </label>

            <label>
              Password
              <input
                type="password"
                placeholder="At least 8 characters"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                required
                minLength={8}
                autoComplete={
                  mode === 'login' ? 'current-password' : 'new-password'
                }
              />
            </label>

            {mode === 'register' && (
              <label>
                Confirm password
                <input
                  type="password"
                  placeholder="Enter your password again"
                  value={passwordConfirmation}
                  onChange={(event) =>
                    setPasswordConfirmation(event.target.value)
                  }
                  required
                  minLength={8}
                  autoComplete="new-password"
                />
              </label>
            )}

            {error && <p className="auth-error">{error}</p>}

            <button className="auth-submit" type="submit" disabled={loading}>
              {loading
                ? 'Please wait...'
                : mode === 'login'
                  ? 'Log in'
                  : 'Create account'}
            </button>
          </form>

          <p className="auth-switch">
            {mode === 'login'
              ? "Don't have an account?"
              : 'Already have an account?'}{' '}
            <button
              type="button"
              onClick={() => {
                setMode(mode === 'login' ? 'register' : 'login')
                setError('')
              }}
            >
              {mode === 'login' ? 'Sign up' : 'Log in'}
            </button>
          </p>
        </div>
      </section>
    </main>
  )
}

export default Auth