import { useEffect, useMemo, useRef, useState } from 'react'
import './App.css'
import Auth from './auth'
import api from './api'
import Icon from './Icon'

const officerRoleOptions = ['Vice President', 'Treasurer', 'Secretary', 'P.I.O', 'Auditor']
const organizationOfficerRoles = ['President', 'Vice President', 'Treasurer', 'Secretary', 'P.I.O', 'Auditor', 'Officer']

function formatCurrency(value) {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
  }).format(Number(value || 0))
}

function formatDate(value) {
  return new Date(`${value}T00:00:00`).toLocaleDateString()
}

function duesReminder(due) {
  if (Number(due.balance) <= 0) return ''

  const dueDate = new Date(`${due.due_date}T00:00:00`)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const daysUntilDue = Math.ceil((dueDate - today) / 86400000)

  if (daysUntilDue < 0) return 'overdue'
  if (daysUntilDue <= 7) return 'due-soon'
  return ''
}

function collegeTheme(college) {
  const themes = {
    CCS: 'org-theme-ccs',
    COE: 'org-theme-coe',
    CBA: 'org-theme-cba',
    CED: 'org-theme-ced',
    CAS: 'org-theme-cas',
    COC: 'org-theme-coc',
  }

  return themes[college] || 'org-theme-default'
}

const collegeLogoFiles = {
  CCS: 'CCS logo.jpg',
  COE: 'COE logo.jpg',
  CBA: 'CBA logo.jpeg',
  CED: 'CED logo.jpg',
  CAS: 'CAS logo.jpg',
  COC: 'COC logo.jpg',
}

function CollegeLogo({ college, organizationName, className = '' }) {
  const [hasLogo, setHasLogo] = useState(true)

  if (!college && !organizationName) return null

  const isStudentGovernment = organizationName?.trim().toLowerCase() === 'student government'
  const logoFile = isStudentGovernment ? 'SPC logo.jpg' : collegeLogoFiles[college]
  const fallbackLabel = college || organizationName?.split(/\s+/).map((part) => part[0]).join('').slice(0, 2)

  return (
    <span className={`college-logo ${className}`} aria-label={`${organizationName || college} logo`}>
      {hasLogo && logoFile
        ? <img src={`${import.meta.env.BASE_URL}college-logos/${encodeURIComponent(logoFile)}`} alt={`${organizationName || college} logo`} onError={() => setHasLogo(false)} />
        : <span aria-hidden="true">{fallbackLabel}</span>}
    </span>
  )
}

function readSavedUser() {
  try {
    const savedUser = localStorage.getItem('orglife_user')
    const savedToken = localStorage.getItem('orglife_token')

    if (savedUser && savedToken) {
      api.defaults.headers.common.Authorization = `Bearer ${savedToken}`
      return JSON.parse(savedUser)
    }
  } catch {
    localStorage.removeItem('orglife_user')
    localStorage.removeItem('orglife_token')
  }

  return null
}

function errorMessage(error) {
  if (!error.response) {
    return `Cannot reach the OrgLife API at ${api.defaults.baseURL}. Start Laravel with "php artisan serve --host 127.0.0.1 --port 8000".`
  }

  const errors = error.response.data?.errors
  return (
    (errors && Object.values(errors)[0]?.[0]) ||
    error.response.data?.message ||
    'The request failed. Please try again.'
  )
}

function App() {
  const handleLogoutRef = useRef(handleLogout)
  const [user, setUser] = useState(readSavedUser)
  const [activePage, setActivePage] = useState('Discover')
  const [organizations, setOrganizations] = useState([])
  const [colleges, setColleges] = useState([])
  const [requests, setRequests] = useState([])
  const [myOrganizations, setMyOrganizations] = useState([])
  const [officerRequests, setOfficerRequests] = useState([])
  const [pendingJoinRequestCount, setPendingJoinRequestCount] = useState(0)
  const [pendingProposalCount, setPendingProposalCount] = useState(0)
  const [pendingPaymentCount, setPendingPaymentCount] = useState(0)
  const [officerMembers, setOfficerMembers] = useState([])
  const [officerEvents, setOfficerEvents] = useState([])
  const [eventAttendance, setEventAttendance] = useState([])
  const [selectedEventId, setSelectedEventId] = useState('')
  const [announcements, setAnnouncements] = useState([])
  const [myEvents, setMyEvents] = useState([])
  const [myDues, setMyDues] = useState([])
  const [duesManagement, setDuesManagement] = useState([])
  const [duesYearFilter, setDuesYearFilter] = useState('all')
  const [duesStatusFilter, setDuesStatusFilter] = useState('all')
  const [selectedDuesOrganizationId, setSelectedDuesOrganizationId] = useState('')
  const [duesSchoolYear, setDuesSchoolYear] = useState(() => {
    const year = new Date().getFullYear()
    return `${year}-${year + 1}`
  })
  const [duesAmount, setDuesAmount] = useState('')
  const [duesDueDate, setDuesDueDate] = useState('')
  const [duesReceipt, setDuesReceipt] = useState(null)
  const [selectedDuesMember, setSelectedDuesMember] = useState(null)
  const [editingDuesPaymentId, setEditingDuesPaymentId] = useState(null)
  const [correctedDuesPaymentAmount, setCorrectedDuesPaymentAmount] = useState('')
  const [duesGcashNumber, setDuesGcashNumber] = useState('')
  const [duesGcashQrFile, setDuesGcashQrFile] = useState(null)
  const [memberPaymentDue, setMemberPaymentDue] = useState(null)
  const [editingMemberPayment, setEditingMemberPayment] = useState(null)
  const [memberPaymentAmount, setMemberPaymentAmount] = useState('')
  const [memberPaymentReference, setMemberPaymentReference] = useState('')
  const [memberPaymentProof, setMemberPaymentProof] = useState(null)
  const [paymentProofPreview, setPaymentProofPreview] = useState('')
  const [paymentProofPreviewId, setPaymentProofPreviewId] = useState(null)
  const [myProposals, setMyProposals] = useState([])
  const [adminProposals, setAdminProposals] = useState([])
  const [draftProposalId, setDraftProposalId] = useState(null)
  const [proposalForm, setProposalForm] = useState({
    name: '',
    category: '',
    college: '',
    cause: '',
    mission: '',
  })
  const [proposalDecisionNotes, setProposalDecisionNotes] = useState({})
  const [editingEventId, setEditingEventId] = useState(null)
  const [eventForm, setEventForm] = useState({
    title: '',
    description: '',
    location: '',
    starts_at: '',
    seat_limit: '',
  })
  const [announcementTitle, setAnnouncementTitle] = useState('')
  const [announcementBody, setAnnouncementBody] = useState('')
  const [profileName, setProfileName] = useState(() => user?.name || '')
  const [profilePictureFile, setProfilePictureFile] = useState(null)
  const [profilePicturePreview, setProfilePicturePreview] = useState('')
  const [selectedOrganization, setSelectedOrganization] = useState(null)
  const [members, setMembers] = useState([])
  const [search, setSearch] = useState('')
  const [college, setCollege] = useState('All Colleges')
  const [type, setType] = useState('All Types')
  const [openOnly, setOpenOnly] = useState(false)
  const [officerOrganizationId, setOfficerOrganizationId] = useState('all')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  useEffect(() => () => {
    if (profilePicturePreview) URL.revokeObjectURL(profilePicturePreview)
  }, [profilePicturePreview])

  useEffect(() => () => {
    if (paymentProofPreview) URL.revokeObjectURL(paymentProofPreview)
  }, [paymentProofPreview])

  async function loadOrganizations() {
    const response = await api.get('/organizations')
    setOrganizations(response.data.data)
    setColleges(response.data.meta.colleges)
  }

  async function loadRequests() {
    const response = await api.get('/membership-requests')
    setRequests(response.data.data)
  }

  async function loadMyOrganizations() {
    const response = await api.get('/my-organizations')
    setMyOrganizations(response.data.data)
  }

  async function loadAnnouncements() {
    const response = await api.get('/announcements')
    setAnnouncements(response.data.data)
  }

  async function loadMyEvents() {
    const response = await api.get('/my-events')
    setMyEvents(response.data.data)
  }

  async function loadMyDues() {
    const response = await api.get('/my-dues')
    setMyDues(response.data.data)
    return response.data.data
  }

  async function loadOfficerRequests(organizationId = officerOrganizationId) {
    const params = organizationId === 'all' ? {} : { organization_id: organizationId }
    const response = await api.get('/officer/requests', { params })
    setOfficerRequests(response.data.data)
    if (organizationId === 'all') setPendingJoinRequestCount(response.data.data.length)
    return response.data.data
  }

  async function loadOfficerMembers(organizationId = officerOrganizationId) {
    const targetOrganizations = organizationId === 'all'
      ? officerOrganizations
      : officerOrganizations.filter((organization) => String(organization.id) === String(organizationId))
    const memberships = await Promise.all(targetOrganizations.map(async (organization) => {
      const response = await api.get(`/organizations/${organization.id}/members`)
      return response.data.data.map((member) => ({
        ...member,
        organization: { id: organization.id, name: organization.name, college: organization.college },
      }))
    }))
    setOfficerMembers(memberships.flat())
  }

  async function loadOfficerEvents(organizationId = officerOrganizationId) {
    const targetOrganizations = organizationId === 'all'
      ? officerOrganizations
      : officerOrganizations.filter((organization) => String(organization.id) === String(organizationId))
    const events = await Promise.all(targetOrganizations.map(async (organization) => {
      const response = await api.get(`/officer/organizations/${organization.id}/events`)
      return response.data.data.map((event) => ({
        ...event,
        organization: { id: organization.id, name: organization.name, college: organization.college },
      }))
    }))
    setOfficerEvents(events.flat())
    setEventAttendance([])
    setSelectedEventId('')
  }

  useEffect(() => {
    if (!user) return

    let cancelled = false
    Promise.all([
      api.get('/organizations'),
      api.get('/membership-requests'),
      api.get('/my-organizations'),
      api.get('/announcements'),
      api.get('/my-events'),
      api.get('/my-dues'),
      api.get('/user'),
    ])
      .then(([orgResponse, requestResponse, membershipResponse, announcementResponse, eventResponse, duesResponse, userResponse]) => {
        if (cancelled) return
        if (user.is_admin !== userResponse.data.user.is_admin) {
          localStorage.setItem('orglife_user', JSON.stringify(userResponse.data.user))
          setUser(userResponse.data.user)
        }
        setOrganizations(orgResponse.data.data)
        setColleges(orgResponse.data.meta.colleges)
        setRequests(requestResponse.data.data)
        setMyOrganizations(membershipResponse.data.data)
        setAnnouncements(announcementResponse.data.data)
        setMyEvents(eventResponse.data.data)
        setMyDues(duesResponse.data.data)
        setError('')
      })
      .catch((loadError) => {
        if (cancelled) return
        if (loadError.response?.status === 401) {
          handleLogoutRef.current()
          return
        }
        setError(errorMessage(loadError))
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [user])

  function handleLogin(userData, token) {
    localStorage.setItem('orglife_token', token)
    localStorage.setItem('orglife_user', JSON.stringify(userData))
    api.defaults.headers.common.Authorization = `Bearer ${token}`
    setError('')
    setNotice('')
    setUser(userData)
    setProfileName(userData.name)
  }

  function updateUserProfile(userData) {
    localStorage.setItem('orglife_user', JSON.stringify(userData))
    setUser(userData)
    setProfileName(userData.name)
  }

  async function handleLogout() {
    try {
      if (localStorage.getItem('orglife_token')) await api.post('/logout')
    } catch (logoutError) {
      console.error('Logout request failed:', logoutError)
    } finally {
      localStorage.removeItem('orglife_token')
      localStorage.removeItem('orglife_user')
      delete api.defaults.headers.common.Authorization
      setUser(null)
      setActivePage('Discover')
      setSelectedOrganization(null)
      setRequests([])
      setMyOrganizations([])
      setOfficerRequests([])
      setPendingJoinRequestCount(0)
      setPendingProposalCount(0)
      setPendingPaymentCount(0)
      setOfficerMembers([])
      setOfficerEvents([])
      setEventAttendance([])
      setAnnouncements([])
      setMyEvents([])
      setMyDues([])
      setDuesManagement([])
      setDuesReceipt(null)
      setSelectedDuesMember(null)
      setEditingDuesPaymentId(null)
      setCorrectedDuesPaymentAmount('')
      setDuesGcashNumber('')
      setDuesGcashQrFile(null)
      setMemberPaymentDue(null)
      setMemberPaymentAmount('')
      setMemberPaymentReference('')
      setMemberPaymentProof(null)
      setPaymentProofPreview('')
      setPaymentProofPreviewId(null)
      setMyProposals([])
      setAdminProposals([])
      setDraftProposalId(null)
      setProposalForm({ name: '', category: '', college: '', cause: '', mission: '' })
      setProposalDecisionNotes({})
      setEditingEventId(null)
      setEventForm({ title: '', description: '', location: '', starts_at: '', seat_limit: '' })
      setSelectedDuesOrganizationId('')
    }
  }

  async function runAction(action, successMessage) {
    setError('')
    setNotice('')
    setLoading(true)
    try {
      await action()
      if (successMessage) setNotice(successMessage)
    } catch (actionError) {
      if (actionError.response?.status === 401) {
        await handleLogout()
      } else {
        setError(errorMessage(actionError))
      }
    } finally {
      setLoading(false)
    }
  }

  async function saveProfile(event) {
    event.preventDefault()
    await runAction(async () => {
      const formData = new FormData()
      formData.append('name', profileName.trim())
      formData.append('_method', 'PATCH')
      if (profilePictureFile) formData.append('profile_picture', profilePictureFile)
      const response = await api.post('/profile', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      updateUserProfile(response.data.user)
      setProfilePictureFile(null)
      setProfilePicturePreview('')
    }, 'Your profile has been updated.')
  }

  async function removeProfilePicture() {
    await runAction(async () => {
      const response = await api.delete('/profile-picture')
      updateUserProfile(response.data.user)
      setProfilePictureFile(null)
      setProfilePicturePreview('')
    }, 'Your profile picture has been removed.')
  }

  async function openOrganization(organizationId) {
    await runAction(async () => {
      resetEventForm()
      const response = await api.get(`/organizations/${organizationId}`)
      setSelectedOrganization(response.data.data)
      setMembers([])
      setActivePage('Organization')
    })
  }

  async function viewMembers() {
    if (!selectedOrganization) return
    await runAction(async () => {
      const response = await api.get(
        `/organizations/${selectedOrganization.id}/members`,
      )
      setMembers(response.data.data)
    })
  }

  async function requestToJoin(organizationId) {
    await runAction(async () => {
      await api.post(`/organizations/${organizationId}/membership-requests`)
      await Promise.all([loadRequests(), loadOrganizations()])
      const response = await api.get(`/organizations/${organizationId}`)
      setSelectedOrganization(response.data.data)
    }, 'Your request to join has been sent.')
  }

  async function withdrawRequest(organizationId) {
    await runAction(async () => {
      await api.delete(`/organizations/${organizationId}/membership-requests`)
      await Promise.all([loadRequests(), loadOrganizations()])
      if (selectedOrganization?.id === organizationId) {
        const response = await api.get(`/organizations/${organizationId}`)
        setSelectedOrganization(response.data.data)
      }
    }, 'Your pending request has been withdrawn.')
  }

  async function openOfficerScreen() {
    await runAction(async () => {
      setOfficerOrganizationId('all')
      await Promise.all([
        loadOfficerRequests('all'),
        loadOfficerMembers('all'),
        loadOfficerEvents('all'),
      ])
      setActivePage('Officer Requests')
    })
  }

  async function openDuesScreen() {
    await runAction(async () => {
      const targetOrganizationId = selectedDuesOrganizationId || String(duesManagerOrganizations[0]?.id || '')
      setSelectedDuesOrganizationId(targetOrganizationId)
      const [dues, management] = await Promise.all([
        loadMyDues(),
        loadDuesManagement(),
      ])
      setMyDues(dues)
      setDuesManagement(management)
      setPendingPaymentCount(management.reduce(
        (count, organization) => count + organization.dues.reduce(
          (dueCount, due) => dueCount + due.members.reduce(
            (memberCount, member) => memberCount + (member.payments || []).filter((payment) => payment.status === 'pending').length,
            0,
          ),
          0,
        ),
        0,
      ))
      const selectedManagement = management.find(
        (organization) => String(organization.organization.id) === String(targetOrganizationId),
      )
      setDuesGcashNumber(selectedManagement?.organization.gcash_number || '')
      setActivePage('Dues')
    })
  }

  async function loadDuesManagement(targetOrganizations = duesManagerOrganizations) {
    const organizationsWithDues = await Promise.all(targetOrganizations.map(async (organization) => {
      const response = await api.get(`/officer/organizations/${organization.id}/dues`)
      return response.data.data
    }))
    return organizationsWithDues
  }

  async function openMyProposals() {
    await runAction(async () => {
      const response = await api.get('/my-organization-proposals')
      setMyProposals(response.data.data)
      setActivePage('My Proposals')
    })
  }

  async function openAdminProposals() {
    await runAction(async () => {
      const response = await api.get('/admin/organization-proposals')
      setAdminProposals(response.data.data)
      setPendingProposalCount(response.data.data.length)
      setActivePage('Admin Proposals')
    })
  }

  function changeProposalField(event) {
    const { name, value } = event.target
    setProposalForm((current) => ({ ...current, [name]: value }))
  }

  async function saveProposalDraft(event) {
    event.preventDefault()
    await runAction(async () => {
      const response = draftProposalId
        ? await api.put(`/organization-proposals/${draftProposalId}`, proposalForm)
        : await api.post('/organization-proposals', proposalForm)
      const savedProposal = response.data.data
      setDraftProposalId(savedProposal.id)
      setMyProposals((current) => [
        savedProposal,
        ...current.filter((proposal) => proposal.id !== savedProposal.id),
      ])
    }, 'Proposal draft saved.')
  }

  async function submitProposal(proposal = null) {
    await runAction(async () => {
      const proposalValues = proposal
        ? {
          name: proposal.name,
          category: proposal.category,
          college: proposal.college,
          cause: proposal.cause,
          mission: proposal.mission,
        }
        : proposalForm
      let proposalId = proposal?.id || draftProposalId
      if (proposalId) {
        await api.put(`/organization-proposals/${proposalId}`, proposalValues)
      } else {
        const draftResponse = await api.post('/organization-proposals', proposalValues)
        proposalId = draftResponse.data.data.id
        setDraftProposalId(proposalId)
      }

      const response = await api.patch(`/organization-proposals/${proposalId}/submit`)
      const submittedProposal = response.data.data
      setMyProposals((current) => [
        submittedProposal,
        ...current.filter((proposal) => proposal.id !== submittedProposal.id),
      ])
      setDraftProposalId(null)
      setProposalForm({ name: '', category: '', college: '', cause: '', mission: '' })
    }, 'Proposal submitted for review.')
  }

  function editProposalDraft(proposal) {
    setDraftProposalId(proposal.id)
    setProposalForm({
      name: proposal.name,
      category: proposal.category,
      college: proposal.college,
      cause: proposal.cause,
      mission: proposal.mission,
    })
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  async function decideProposal(proposalId, status) {
    await runAction(async () => {
      await api.patch(`/admin/organization-proposals/${proposalId}`, {
        status,
        decision_note: proposalDecisionNotes[proposalId] || '',
      })
      const [proposalResponse] = await Promise.all([
        api.get('/admin/organization-proposals'),
        loadOrganizations(),
        loadMyOrganizations(),
      ])
      setAdminProposals(proposalResponse.data.data)
      setPendingProposalCount(proposalResponse.data.data.length)
    }, `Proposal ${status}.`)
  }

  function startEditingEvent(event) {
    const startsAt = new Date(event.starts_at)
    startsAt.setMinutes(startsAt.getMinutes() - startsAt.getTimezoneOffset())
    setEditingEventId(event.id)
    setEventForm({
      title: event.title,
      description: event.description || '',
      location: event.location || '',
      starts_at: startsAt.toISOString().slice(0, 16),
      seat_limit: String(event.seat_limit || ''),
    })
  }

  function resetEventForm() {
    setEditingEventId(null)
    setEventForm({ title: '', description: '', location: '', starts_at: '', seat_limit: '' })
  }

  async function saveOrganizationEvent(event) {
    event.preventDefault()
    if (!selectedOrganization) return

    await runAction(async () => {
      const path = `/organizations/${selectedOrganization.id}/events`
      if (editingEventId) {
        await api.patch(`${path}/${editingEventId}`, eventForm)
      } else {
        await api.post(path, eventForm)
      }
      const [organizationResponse] = await Promise.all([
        api.get(`/organizations/${selectedOrganization.id}`),
        loadMyEvents(),
        loadOfficerEvents(),
      ])
      setSelectedOrganization(organizationResponse.data.data)
      resetEventForm()
    }, editingEventId ? 'Event updated.' : 'Event created.')
  }

  async function saveDuesSchedule(event) {
    event.preventDefault()
    if (!selectedDuesOrganizationId) return

    await runAction(async () => {
      await api.post(`/officer/organizations/${selectedDuesOrganizationId}/dues`, {
        school_year: duesSchoolYear.trim(),
        amount: duesAmount,
        due_date: duesDueDate,
      })
      const [dues, management] = await Promise.all([
        loadMyDues(),
        loadDuesManagement(),
      ])
      setMyDues(dues)
      setDuesManagement(management)
    }, 'Dues schedule saved.')
  }

  async function saveGcashDetails(event) {
    event.preventDefault()
    if (!selectedDuesOrganizationId) return

    await runAction(async () => {
      const formData = new FormData()
      formData.append('gcash_number', duesGcashNumber.trim())
      if (duesGcashQrFile) formData.append('gcash_qr', duesGcashQrFile)
      await api.post(`/officer/organizations/${selectedDuesOrganizationId}/gcash`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      const management = await loadDuesManagement()
      setDuesManagement(management)
      setDuesGcashQrFile(null)
    }, 'GCash payment details saved.')
  }

  function changeDuesOrganization(organizationId) {
    setSelectedDuesOrganizationId(organizationId)
    setDuesGcashNumber(
      duesManagement.find((organization) => String(organization.organization.id) === String(organizationId))
        ?.organization.gcash_number || '',
    )
    setDuesGcashQrFile(null)
  }

  function openMemberPayment(due, payment = null) {
    setMemberPaymentDue(due)
    setEditingMemberPayment(payment)
    setMemberPaymentAmount(payment
      ? payment.amount
      : Math.max(0, Number(due.balance) - Number(due.pending_amount || 0)).toFixed(2))
    setMemberPaymentReference(payment?.reference_last_four || '')
    setMemberPaymentProof(null)
  }

  async function submitMemberDuesPayment(event) {
    event.preventDefault()
    if (!memberPaymentDue) return

    await runAction(async () => {
      const formData = new FormData()
      formData.append('amount', memberPaymentAmount)
      formData.append('reference_last_four', memberPaymentReference.trim())
      if (memberPaymentProof) formData.append('proof', memberPaymentProof)
      if (editingMemberPayment) formData.append('_method', 'PATCH')
      const paymentPath = editingMemberPayment
        ? `/my-dues/${memberPaymentDue.id}/payments/${editingMemberPayment.id}`
        : `/my-dues/${memberPaymentDue.id}/payments`
      await api.post(paymentPath, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      setMyDues(await loadMyDues())
      setMemberPaymentDue(null)
      setEditingMemberPayment(null)
      setMemberPaymentAmount('')
      setMemberPaymentReference('')
      setMemberPaymentProof(null)
    }, editingMemberPayment
      ? 'Payment submission updated. It remains pending until reviewed.'
      : 'Payment proof submitted. It will count toward your balance after review.')
  }

  async function recordDuesPayment(organizationId, dueId, userId, amount) {
    await runAction(async () => {
      const response = await api.post(
        `/officer/organizations/${organizationId}/dues/${dueId}/payments`,
        { user_id: userId, amount },
      )
      setDuesReceipt(response.data.data)
      const [dues, management] = await Promise.all([
        loadMyDues(),
        loadDuesManagement(),
      ])
      setMyDues(dues)
      setDuesManagement(management)
    }, 'Payment recorded. Print the receipt stub below.')
  }

  function openDuesMemberPayments(organizationId, due, member) {
    setSelectedDuesMember({ organizationId, due, member })
    setEditingDuesPaymentId(null)
    setCorrectedDuesPaymentAmount('')
  }

  async function correctDuesPayment(event) {
    event.preventDefault()
    if (!selectedDuesMember || !editingDuesPaymentId) return

    await runAction(async () => {
      await api.patch(
        `/officer/organizations/${selectedDuesMember.organizationId}/dues/${selectedDuesMember.due.id}/payments/${editingDuesPaymentId}`,
        { amount: correctedDuesPaymentAmount },
      )
      const [dues, management] = await Promise.all([
        loadMyDues(),
        loadDuesManagement(),
      ])
      setMyDues(dues)
      setDuesManagement(management)
      const refreshedOrganization = management.find(
        (organization) => String(organization.organization.id) === String(selectedDuesMember.organizationId),
      )
      const refreshedDue = refreshedOrganization?.dues.find(
        (due) => String(due.id) === String(selectedDuesMember.due.id),
      )
      const refreshedMember = refreshedDue?.members.find(
        (member) => String(member.user_id) === String(selectedDuesMember.member.user_id),
      )
      if (refreshedOrganization && refreshedDue && refreshedMember) {
        setSelectedDuesMember({
          organizationId: selectedDuesMember.organizationId,
          due: refreshedDue,
          member: refreshedMember,
        })
      }
      setEditingDuesPaymentId(null)
      setCorrectedDuesPaymentAmount('')
    }, 'Payment amount corrected.')
  }

  function startCorrectingDuesPayment(payment) {
    setEditingDuesPaymentId(payment.id)
    setCorrectedDuesPaymentAmount(payment.amount)
  }

  async function reviewGcashPayment(payment, status) {
    if (!selectedDuesMember) return

    await runAction(async () => {
      await api.patch(
        `/officer/organizations/${selectedDuesMember.organizationId}/dues/${selectedDuesMember.due.id}/payments/${payment.id}/review`,
        { status },
      )
      const [dues, management, pendingResponse] = await Promise.all([
        loadMyDues(),
        loadDuesManagement(),
        api.get('/officer/pending-dues-payments'),
      ])
      setMyDues(dues)
      setDuesManagement(management)
      setPendingPaymentCount(pendingResponse.data.data.count)
      const refreshedOrganization = management.find(
        (organization) => String(organization.organization.id) === String(selectedDuesMember.organizationId),
      )
      const refreshedDue = refreshedOrganization?.dues.find(
        (due) => String(due.id) === String(selectedDuesMember.due.id),
      )
      const refreshedMember = refreshedDue?.members.find(
        (member) => String(member.user_id) === String(selectedDuesMember.member.user_id),
      )
      if (refreshedDue && refreshedMember) {
        setSelectedDuesMember({ ...selectedDuesMember, due: refreshedDue, member: refreshedMember })
      }
    }, status === 'pending'
      ? 'Decision canceled. The payment is pending review again.'
      : `Payment ${status}.`)
  }

  async function viewGcashProof(payment) {
    if (!selectedDuesMember) return

    await runAction(async () => {
      const response = await api.get(
        `/officer/organizations/${selectedDuesMember.organizationId}/dues/${selectedDuesMember.due.id}/payments/${payment.id}/proof`,
        { responseType: 'blob' },
      )
      if (paymentProofPreview) URL.revokeObjectURL(paymentProofPreview)
      setPaymentProofPreview(URL.createObjectURL(response.data))
      setPaymentProofPreviewId(payment.id)
    })
  }

  function closeDuesMemberDialog() {
    setSelectedDuesMember(null)
    setEditingDuesPaymentId(null)
    if (paymentProofPreview) URL.revokeObjectURL(paymentProofPreview)
    setPaymentProofPreview('')
    setPaymentProofPreviewId(null)
  }

  function editDuesSchedule(due) {
    setDuesSchoolYear(due.school_year)
    setDuesAmount(due.amount)
    setDuesDueDate(due.due_date)
  }

  async function changeOfficerOrganization(organizationId) {
    setOfficerOrganizationId(organizationId)
    await runAction(() => Promise.all([
      loadOfficerRequests(organizationId),
      loadOfficerMembers(organizationId),
      loadOfficerEvents(organizationId),
    ]))
  }

  async function rsvpToEvent(eventId, rsvped) {
    if (!selectedOrganization) return
    await runAction(async () => {
      const path = `/organizations/${selectedOrganization.id}/events/${eventId}/rsvp`
      if (rsvped) {
        await api.delete(path)
      } else {
        await api.post(path)
      }
      const [organizationResponse] = await Promise.all([
        api.get(`/organizations/${selectedOrganization.id}`),
        loadMyEvents(),
      ])
      setSelectedOrganization(organizationResponse.data.data)
    }, rsvped ? 'Your RSVP has been cancelled.' : 'You are RSVP’d for this event.')
  }

  async function openEventAttendance(eventId) {
    await runAction(async () => {
      const response = await api.get(`/officer/events/${eventId}/attendance`)
      setEventAttendance(response.data.data)
      setSelectedEventId(String(eventId))
    })
  }

  async function markAttendance(eventId, userId) {
    await runAction(async () => {
      await api.post(`/officer/events/${eventId}/attendance/${userId}`)
      const event = officerEvents.find((item) => String(item.id) === String(eventId))
      if (!event) return
      const [attendanceResponse, eventsResponse] = await Promise.all([
        api.get(`/officer/events/${eventId}/attendance`),
        api.get(`/officer/organizations/${event.organization_id}/events`),
      ])
      const refreshedEvents = eventsResponse.data.data.map((item) => ({
        ...item,
        organization: event.organization,
      }))
      setOfficerEvents((currentEvents) => [
        ...currentEvents.filter((item) => item.organization_id !== event.organization_id),
        ...refreshedEvents,
      ])
      setEventAttendance(attendanceResponse.data.data)
      setSelectedEventId(String(eventId))
    }, 'Attendance marked.')
  }

  async function decideRequest(requestId, status) {
    await runAction(async () => {
      await api.patch(`/officer/requests/${requestId}`, { status })
      await Promise.all([loadOfficerRequests(), loadRequests(), loadMyOrganizations()])
      await loadOfficerMembers()
    }, `Request ${status}.`)
  }

  async function removeOrganizationMember(organizationId, memberId) {
    await runAction(async () => {
      await api.delete(`/officer/organizations/${organizationId}/members/${memberId}`)
      await loadOfficerMembers()
    }, 'Member removed from the organization.')
  }

  async function assignOrganizationRole(organizationId, memberId, role) {
    await runAction(async () => {
      await api.patch(`/officer/organizations/${organizationId}/members/${memberId}`, { role })
      await loadOfficerMembers()
    }, 'Member role updated.')
  }

  async function postAnnouncement(title, body) {
    if (!selectedOrganization) return
    await runAction(async () => {
      await api.post(`/organizations/${selectedOrganization.id}/announcements`, { title, body })
      const [organizationResponse] = await Promise.all([
        api.get(`/organizations/${selectedOrganization.id}`),
        loadAnnouncements(),
      ])
      setSelectedOrganization(organizationResponse.data.data)
      setAnnouncementTitle('')
      setAnnouncementBody('')
    }, 'Announcement posted.')
  }

  async function deleteAnnouncement(organizationId, announcementId) {
    if (!window.confirm('Delete this announcement? This cannot be undone.')) return
    await runAction(async () => {
      await api.delete(`/organizations/${organizationId}/announcements/${announcementId}`)
      await loadAnnouncements()
      if (String(selectedOrganization?.id) === String(organizationId)) {
        const response = await api.get(`/organizations/${organizationId}`)
        setSelectedOrganization(response.data.data)
      }
    }, 'Announcement deleted.')
  }

  async function cancelOrganizationEvent(organizationId, eventId) {
    if (!window.confirm('Cancel this event? It will stay visible, and RSVP/attendance history will be preserved.')) return
    await runAction(async () => {
      await api.patch(`/organizations/${organizationId}/events/${eventId}/cancel`)
      const [organizationResponse] = await Promise.all([
        selectedOrganization?.id === organizationId
          ? api.get(`/organizations/${organizationId}`)
          : Promise.resolve(null),
        loadMyEvents(),
        loadOfficerEvents(),
      ])
      setEventAttendance([])
      setSelectedEventId('')
      if (String(editingEventId) === String(eventId)) resetEventForm()
      if (organizationResponse) setSelectedOrganization(organizationResponse.data.data)
    }, 'Event cancelled. RSVP and attendance history have been preserved.')
  }

  const filteredOrganizations = useMemo(() => {
    const term = search.trim().toLowerCase()
    return organizations.filter((organization) => {
      const matchesSearch =
        !term ||
        organization.name.toLowerCase().includes(term) ||
        organization.cause.toLowerCase().includes(term)
      const matchesCollege =
        college === 'All Colleges' ||
        organization.college === college ||
        organization.college === 'All Colleges'
      const matchesType = type === 'All Types' || organization.category === type
      return matchesSearch && matchesCollege && matchesType && (!openOnly || organization.open)
    })
  }, [organizations, search, college, type, openOnly])

  const typeOptions = useMemo(
    () => [...new Set(organizations.map((organization) => organization.category))].sort(),
    [organizations],
  )
  const officerOrganizations = user?.is_admin
    ? organizations
    : myOrganizations.filter((organization) => organization.role === 'President')
  const isOrganizationOfficer = myOrganizations.some((organization) =>
    organizationOfficerRoles.includes(organization.role))
  const canReviewProposals = user?.is_admin
    || myOrganizations.some((organization) => organization.role === 'President')
  const duesManagerOrganizations = user?.is_admin
    ? organizations
    : myOrganizations.filter((organization) => ['President', 'Treasurer'].includes(organization.role))
  const duesManagerScope = duesManagerOrganizations.map((organization) => organization.id).join(',')
  const duesYears = [...new Set([
    ...myDues.map((due) => due.school_year),
    ...duesManagement.flatMap((organization) => organization.dues.map((due) => due.school_year)),
  ])].sort().reverse()
  const matchesDuesStatus = (status) => duesStatusFilter === 'all'
    || status === duesStatusFilter
    || (duesStatusFilter === 'unpaid' && status !== 'paid')
  const filteredMyDues = myDues.filter((due) =>
    (duesYearFilter === 'all' || due.school_year === duesYearFilter)
    && matchesDuesStatus(due.status))
  const selectedDuesManagement = duesManagement.find(
    (organization) => String(organization.organization.id) === String(selectedDuesOrganizationId),
  )
  const selectedOrganizationForDues = duesManagerOrganizations.find(
    (organization) => String(organization.id) === String(selectedDuesOrganizationId),
  )
  const selectedDuesOrganization = selectedDuesManagement || (selectedOrganizationForDues
    ? { organization: selectedOrganizationForDues, dues: [] }
    : null)
  const filteredManagerDues = (selectedDuesOrganization?.dues || []).filter((due) =>
    (duesYearFilter === 'all' || due.school_year === duesYearFilter)
    && (duesStatusFilter === 'all' || due.members.some((member) => matchesDuesStatus(member.status))))
  const duesReminders = myDues.filter((due) => duesReminder(due))
  const officerOrganizationScope = officerOrganizations.map((organization) => organization.id).join(',')

  useEffect(() => {
    if (!user) return undefined

    let active = true
    const refreshReviewNotifications = async () => {
      try {
        const [requestResponse, proposalResponse, paymentResponse] = await Promise.all([
          user.is_admin || officerOrganizationScope
            ? api.get('/officer/requests')
            : Promise.resolve(null),
          canReviewProposals
            ? api.get('/admin/organization-proposals')
            : Promise.resolve(null),
          user.is_admin || duesManagerScope
            ? api.get('/officer/pending-dues-payments')
            : Promise.resolve(null),
        ])
        if (!active) return
        setPendingJoinRequestCount(requestResponse?.data.data.length || 0)
        setPendingProposalCount(proposalResponse?.data.data.length || 0)
        setPendingPaymentCount(paymentResponse?.data.data.count || 0)
      } catch (notificationError) {
        if (!active) return
        if (notificationError.response?.status === 401) {
          handleLogoutRef.current()
        } else {
          setError(errorMessage(notificationError))
        }
      }
    }

    refreshReviewNotifications()
    const intervalId = window.setInterval(() => {
      if (document.visibilityState === 'visible') refreshReviewNotifications()
    }, 30000)
    const handleVisibilityChange = () => {
      if (document.visibilityState === 'visible') refreshReviewNotifications()
    }
    document.addEventListener('visibilitychange', handleVisibilityChange)

    return () => {
      active = false
      window.clearInterval(intervalId)
      document.removeEventListener('visibilitychange', handleVisibilityChange)
    }
  }, [user, canReviewProposals, officerOrganizationScope, duesManagerScope])

  if (!user) return <Auth onLogin={handleLogin} />

  function navTo(page) {
    setError('')
    setNotice('')
    setSelectedOrganization(null)
    setActivePage(page)
  }

  return (
    <div className="app">
      <aside className="sidebar">
        <div className="brand">
          <div className="brand-icon" aria-hidden="true"><span /></div>
          <div><h2>OrgLife</h2><p>Campus community</p></div>
        </div>
        <p className="nav-label">MENU</p>
        {['Dashboard', 'Discover', 'My Organizations', ...(!user.is_admin ? ['My Requests'] : [])].map((page) => (
          <button key={page} className={`nav-link ${activePage === page ? 'active' : ''}`} onClick={() => navTo(page)}>
            <Icon name={page === 'Dashboard' ? 'dashboard' : page === 'Discover' ? 'search' : page === 'My Organizations' ? 'organizations' : 'requests'} />
            <span>{page}</span>
          </button>
        ))}
        <button className={`nav-link ${activePage === 'Profile Settings' ? 'active' : ''}`} onClick={() => navTo('Profile Settings')}>
          <Icon name="members" /><span>Profile Settings</span>
        </button>
        {isOrganizationOfficer && <button className={`nav-link ${activePage === 'My Proposals' ? 'active' : ''}`} onClick={openMyProposals}>
          <Icon name="announcement" /><span>My Proposals</span>
        </button>}
        {canReviewProposals && <button className={`nav-link ${activePage === 'Admin Proposals' ? 'active' : ''}`} onClick={openAdminProposals}>
          <Icon name="officer" /><span>{user.is_admin ? 'Admin Proposals' : 'Review Proposals'}</span>
          {pendingProposalCount > 0 && <span className="nav-count">{pendingProposalCount}</span>}
        </button>}
        <button className={`nav-link ${activePage === 'Dues' ? 'active' : ''}`} onClick={openDuesScreen}>
          <Icon name="dues" /><span>Payments</span>
          {duesReminders.length + pendingPaymentCount > 0 && <span className="nav-count">{duesReminders.length + pendingPaymentCount}</span>}
        </button>
        {officerOrganizations.length > 0 && (
          <button className={`nav-link ${activePage === 'Officer Requests' ? 'active' : ''}`} onClick={openOfficerScreen}>
            <Icon name="officer" /><span>Officer Tools</span>
            {pendingJoinRequestCount > 0 && <span className="nav-count">{pendingJoinRequestCount}</span>}
          </button>
        )}
        <div className="sidebar-bottom">
          <button
            type="button"
            className="profile-navigation-button"
            onClick={() => navTo('Profile Settings')}
            aria-label="Open profile settings"
          >
            <UserAvatar name={user.name} src={user.profile_picture_url} />
          </button>
          <div className="sidebar-user">
            <button type="button" className="profile-name-button" onClick={() => navTo('Profile Settings')}>
              {user.name}
            </button>
            <p>{user.email}</p>
            <button className="logout-button" onClick={handleLogout}>Log out</button>
          </div>
        </div>
      </aside>

      <main className="main-content">
        <header className="topbar">
          <div>
            <p className="breadcrumb">Home / {activePage}</p>
            <h1>{activePage}</h1>
          </div>
          <div className="topbar-right"><span className="status-dot" />Student Portal<button type="button" className="profile-navigation-button" onClick={() => navTo('Profile Settings')} aria-label="Open profile settings"><UserAvatar name={user.name} src={user.profile_picture_url} size="small" /></button></div>
        </header>

        {error && <div className="app-message error-message" role="alert">{error}</div>}
        {notice && <div className="app-message success-message" role="status">{notice}</div>}
        {loading && <p className="loading-message" role="status">Loading…</p>}

        {activePage === 'Dashboard' && (
          <>
            <section className="welcome">
              <div><span className="eyebrow">YOUR CAMPUS, YOUR COMMUNITY</span><div className="welcome-profile"><button type="button" className="profile-navigation-button" onClick={() => navTo('Profile Settings')} aria-label="Open profile settings"><UserAvatar name={user.name} src={user.profile_picture_url} size="compact" /></button><button type="button" className="profile-name-button welcome-name-button" onClick={() => navTo('Profile Settings')}>Welcome, {user.name}!</button></div><h2>Find your people.</h2><p>Discover organizations, explore your interests, and find a community where you belong.</p><button className="primary-button" onClick={() => navTo('Discover')}>Explore organizations <Icon name="arrowRight" /></button></div>
              <div className="welcome-art" aria-hidden="true"><div className="art-circle"><span /><span /><span /></div></div>
            </section>
            <section className="stats-grid">
              <Stat icon="organizations" label="Student organizations" value={organizations.length} tone="purple" />
              <Stat icon="requests" label="My requests" value={requests.length} tone="green" />
              <Stat icon="members" label="My memberships" value={myOrganizations.length} tone="orange" />
            </section>
            {duesReminders.length > 0 && <section className="dues-reminder-panel">
              <div className="dues-reminder-heading">
                <div><span className="eyebrow">PAYMENT REMINDERS</span><h2>Dues need your attention</h2><p>Upcoming deadlines and outstanding balances from your organizations.</p></div>
                <button className="outline-button" onClick={openDuesScreen}>View payments <Icon name="arrowRight" /></button>
              </div>
              <div className="dues-reminder-list">{duesReminders.map((due) => {
                const reminder = duesReminder(due)
                return <article className={`dues-reminder-item ${reminder}`} key={due.id}>
                  <div><strong>{due.organization.name}</strong><span>{due.school_year} · {reminder === 'overdue' ? `Overdue since ${formatDate(due.due_date)}` : `Due ${formatDate(due.due_date)}`}</span></div>
                  <strong>{formatCurrency(due.balance)} remaining</strong>
                </article>
              })}</div>
            </section>}
            <section className="content-panel dashboard-section">
              <div className="section-heading"><div><span className="eyebrow">YOUR COMMUNITIES</span><h2>Organizations you belong to</h2><p>Quick access to your organizations and member roles.</p></div><span className="result-count">{myOrganizations.length} joined</span></div>
              {myOrganizations.length ? <div className="dashboard-org-list">{myOrganizations.map((membership) => (
                <article className={`dashboard-org-row ${collegeTheme(membership.college)}`} key={membership.id}>
                  <div className="dashboard-org-info"><CollegeLogo college={membership.college} organizationName={membership.name} /><div><h3>{membership.name}</h3><p>{membership.category} · {membership.college}</p></div></div>
                  <span className="role-pill">{membership.role}</span>
                  <button className="text-button" onClick={() => openOrganization(membership.id)}>Open <Icon name="arrowRight" /></button>
                </article>
              ))}</div> : <EmptyState title="No organizations yet" description="Join an organization to see it here." action="Discover organizations" onAction={() => navTo('Discover')} />}
            </section>
            <section className="content-panel dashboard-section">
              <div className="section-heading"><div><span className="eyebrow">COMMUNITY UPDATES</span><h2>Announcements</h2><p>Latest posts from every organization you belong to.</p></div></div>
              <AnnouncementList
                announcements={announcements}
                onOpenOrganization={openOrganization}
                onDelete={deleteAnnouncement}
                loading={loading}
                canDelete={(announcement) => myOrganizations.some((membership) =>
                  String(membership.id) === String(announcement.organization.id) && membership.role === 'President') || user.is_admin}
              />
            </section>
            <section className="content-panel dashboard-section">
              <div className="section-heading"><div><span className="eyebrow">YOUR CALENDAR</span><h2>Organization events</h2><p>Upcoming and past events from every organization you’ve joined.</p></div></div>
              <EventList
                events={myEvents}
                onOpenOrganization={openOrganization}
                loading={loading}
              />
            </section>
          </>
        )}

        {activePage === 'Profile Settings' && (
          <section className="content-panel profile-settings">
            <div className="section-heading">
              <div><span className="eyebrow">YOUR ACCOUNT</span><h2>Profile settings</h2><p>Update how you appear to your campus community.</p></div>
            </div>
            <form className="profile-settings-form" onSubmit={saveProfile}>
              <div className="profile-picture-editor">
                <UserAvatar
                  name={profileName || user.name}
                  src={profilePicturePreview || user.profile_picture_url}
                  size="large"
                />
                <div>
                  <strong>Profile picture</strong>
                  <p>Use a JPG, PNG, or WebP image up to 5 MB. Images are cropped to fit your avatar.</p>
                  <label className="outline-button profile-upload-button">
                    Choose image
                    <input
                      type="file"
                      accept="image/jpeg,image/png,image/webp"
                      onChange={(changeEvent) => {
                        const file = changeEvent.target.files?.[0]
                        if (!file) return
                        setProfilePictureFile(file)
                        setProfilePicturePreview(URL.createObjectURL(file))
                      }}
                    />
                  </label>
                  {(profilePicturePreview || user.profile_picture_url) && (
                    <button type="button" className="text-button danger-text" disabled={loading} onClick={removeProfilePicture}>Remove picture</button>
                  )}
                </div>
              </div>
              <label className="profile-name-field">
                Display name
                <input value={profileName} onChange={(changeEvent) => setProfileName(changeEvent.target.value)} required maxLength={255} />
              </label>
              <label className="profile-name-field">
                Email address
                <input value={user.email} readOnly />
              </label>
              <button className="primary-button" disabled={loading || !profileName.trim()}>Save profile</button>
            </form>
          </section>
        )}

        {activePage === 'Discover' && (
          <section className="directory-section">
            <div className="section-heading"><div><span className="eyebrow">GET INVOLVED</span><h2>Discover organizations</h2><p>Find an organization that matches your passion.</p></div><span className="result-count">{filteredOrganizations.length} organizations</span></div>
            <div className="filters">
              <input type="search" placeholder="Search by name or cause..." value={search} onChange={(event) => setSearch(event.target.value)} aria-label="Search by name or cause" />
              <select value={college} onChange={(event) => setCollege(event.target.value)} aria-label="Filter by college"><option>All Colleges</option>{colleges.map((item) => <option key={item.code} value={item.code}>{item.name} ({item.code})</option>)}</select>
              <select value={type} onChange={(event) => setType(event.target.value)} aria-label="Filter by type"><option>All Types</option>{typeOptions.map((item) => <option key={item}>{item}</option>)}</select>
              <label className="open-filter"><input type="checkbox" checked={openOnly} onChange={(event) => setOpenOnly(event.target.checked)} />Open membership</label>
            </div>
            {filteredOrganizations.length > 0 ? (
              <div className="organization-grid">
                {filteredOrganizations.map((organization) => (
                  <article className={`org-card ${collegeTheme(organization.college)}`} key={organization.id}>
                    <div className="org-card-top"><CollegeLogo college={organization.college} organizationName={organization.name} className="org-avatar" /><span className={`membership ${organization.open ? 'open' : 'closed'}`}>{organization.open ? 'Open' : 'Closed'}</span></div>
                    <span className="category">{organization.category}</span><h3>{organization.name}</h3><p className="org-cause">{organization.cause}</p>
                    <div className="org-meta"><span>{organization.college}</span><span>{organization.members} members</span></div>
                    {organization.request_status && organization.request_status !== 'approved' && <p className={`status-label status-${organization.request_status}`}>Request {organization.request_status}</p>}
                    <button className="outline-button" onClick={() => openOrganization(organization.id)}>View organization <Icon name="arrowRight" /></button>
                  </article>
                ))}
              </div>
            ) : <EmptyState title="No organizations found" description="Try another search term or change your filters." />}
          </section>
        )}

        {activePage === 'Organization' && selectedOrganization && (
          <OrganizationDetails
            organization={selectedOrganization}
            members={members}
            loading={loading}
            onBack={() => navTo('Discover')}
            onJoin={() => requestToJoin(selectedOrganization.id)}
            onWithdraw={() => withdrawRequest(selectedOrganization.id)}
            onViewMembers={viewMembers}
            onPostAnnouncement={postAnnouncement}
            announcementTitle={announcementTitle}
            announcementBody={announcementBody}
            onAnnouncementTitleChange={setAnnouncementTitle}
            onAnnouncementBodyChange={setAnnouncementBody}
            onRsvp={rsvpToEvent}
            onDeleteAnnouncement={deleteAnnouncement}
            onCancelEvent={cancelOrganizationEvent}
            onEditEvent={startEditingEvent}
            eventForm={eventForm}
            editingEventId={editingEventId}
            onEventFormChange={(event) => {
              const { name, value } = event.target
              setEventForm((current) => ({ ...current, [name]: value }))
            }}
            onSaveEvent={saveOrganizationEvent}
            onCancelEditEvent={resetEventForm}
            isAdmin={user.is_admin}
          />
        )}

        {activePage === 'My Requests' && (
          <section className="content-panel">
            <div className="section-heading"><div><span className="eyebrow">MEMBERSHIP</span><h2>My join requests</h2><p>Track decisions or withdraw a pending request.</p></div></div>
            {requests.length ? <div className="request-list">{requests.map((request) => (
              <article className="request-row" key={request.id}>
                <div><h3>{request.organization.name}</h3><p>{request.organization.category} · {request.organization.college}</p></div>
                <span className={`status-label status-${request.status}`}>{request.status}</span>
                {request.status === 'pending' && <button className="text-button danger-text" disabled={loading} onClick={() => withdrawRequest(request.organization_id)}>Withdraw request</button>}
              </article>
            ))}</div> : <EmptyState title="No join requests yet" description="Explore organizations and request to join a community." action="Discover organizations" onAction={() => navTo('Discover')} />}
          </section>
        )}

        {activePage === 'My Proposals' && isOrganizationOfficer && (
          <section className="content-panel proposal-page">
            <div className="section-heading">
              <div><span className="eyebrow">CAMPUS INITIATIVES</span><h2>Start an organization</h2><p>Save your proposal as a draft, submit it when it’s ready, and track the review decision.</p></div>
            </div>
            <form className="proposal-form" onSubmit={saveProposalDraft}>
              <div className="proposal-form-heading"><div><h3>{draftProposalId ? 'Edit your proposal draft' : 'Organization proposal'}</h3><p>Tell us what your organization will do and who it will serve.</p></div>{draftProposalId && <span className="proposal-status status-draft">Draft</span>}</div>
              <div className="proposal-form-grid">
                <label>Organization name<input name="name" value={proposalForm.name} onChange={changeProposalField} maxLength="160" required /></label>
                <label>Category<input name="category" value={proposalForm.category} onChange={changeProposalField} maxLength="100" placeholder="Academic, cultural, service..." required /></label>
                <label>College<select name="college" value={proposalForm.college} onChange={changeProposalField} required><option value="">Select a college</option>{colleges.map((item) => <option value={item.code} key={item.code}>{item.name} ({item.code})</option>)}<option value="All Colleges">All Colleges</option></select></label>
                <label>Cause<input name="cause" value={proposalForm.cause} onChange={changeProposalField} maxLength="255" required /></label>
                <label className="proposal-mission-field">Mission<textarea name="mission" value={proposalForm.mission} onChange={changeProposalField} rows="4" maxLength="5000" required /></label>
              </div>
              <div className="proposal-form-actions">
                <button className="outline-button" type="submit" disabled={loading}>Save draft</button>
                <button className="primary-button" type="button" disabled={loading || !proposalForm.name.trim() || !proposalForm.category.trim() || !proposalForm.college || !proposalForm.cause.trim() || !proposalForm.mission.trim()} onClick={() => submitProposal()}>Submit for review</button>
                {draftProposalId && <button className="text-button" type="button" onClick={() => { setDraftProposalId(null); setProposalForm({ name: '', category: '', college: '', cause: '', mission: '' }) }}>Clear form</button>}
              </div>
            </form>

            <section className="proposal-list-section">
              <div className="dues-section-heading"><div><h3>Your proposals</h3><p>Follow each proposal from draft through its final decision.</p></div><span className="member-total">{myProposals.length} proposals</span></div>
              {myProposals.length ? <div className="proposal-list">{myProposals.map((proposal) => (
                <article className="proposal-card" key={proposal.id}>
                  <header><div><h4>{proposal.name}</h4><span>{proposal.category} · {proposal.college}</span></div><span className={`proposal-status status-${proposal.status}`}>{proposal.status}</span></header>
                  <p>{proposal.cause}</p>
                  <p className="proposal-mission">{proposal.mission}</p>
                  <div className="proposal-progress" aria-label={`Proposal status: ${proposal.status}`}>
                    {['Draft', 'Submitted', 'Decision'].map((step, index) => {
                      const completed = proposal.status === 'draft' ? index === 0 : proposal.status === 'submitted' ? index < 2 : true
                      return <span className={completed ? 'complete' : ''} key={step}>{step}</span>
                    })}
                  </div>
                  {proposal.status === 'draft' && <div className="proposal-card-actions">
                    <button className="outline-button" disabled={loading} onClick={() => editProposalDraft(proposal)}>Edit draft</button>
                    <button className="primary-button" disabled={loading} onClick={() => submitProposal(proposal)}>Submit for review</button>
                  </div>}
                  {proposal.status !== 'draft' && <div className="proposal-decision-details">
                    {proposal.submitted_at && <span>Submitted {new Date(proposal.submitted_at).toLocaleDateString()}</span>}
                    {proposal.decided_at && <span>Decision {new Date(proposal.decided_at).toLocaleDateString()}</span>}
                    {proposal.decision_maker && <span>Reviewed by {proposal.decision_maker.name}</span>}
                    {proposal.decision_note && <p>{proposal.decision_note}</p>}
                    {proposal.organization && <button className="text-button" onClick={() => openOrganization(proposal.organization.id)}>Open approved organization <Icon name="arrowRight" /></button>}
                  </div>}
                </article>
              ))}</div> : <EmptyState title="No proposals yet" description="Save a draft above to start tracking a new organization proposal." />}
            </section>
          </section>
        )}

        {activePage === 'Admin Proposals' && canReviewProposals && (
          <section className="content-panel proposal-page">
            <div className="section-heading"><div><span className="eyebrow">PROPOSAL REVIEW</span><h2>Organization proposals</h2><p>Review submitted proposals. Approval creates the organization and makes the proposer its president.</p></div><span className="member-total">{adminProposals.length} awaiting decision</span></div>
            {adminProposals.length ? <div className="proposal-list admin-proposal-list">{adminProposals.map((proposal) => (
              <article className="proposal-card" key={proposal.id}>
                <header><div><h3>{proposal.name}</h3><span>{proposal.proposer.name} · {proposal.proposer.email}</span></div><span className="proposal-status status-submitted">Submitted</span></header>
                <div className="proposal-meta-grid"><span><strong>Category</strong>{proposal.category}</span><span><strong>College</strong>{proposal.college}</span><span><strong>Submitted</strong>{new Date(proposal.submitted_at).toLocaleString()}</span></div>
                <p><strong>Cause</strong> {proposal.cause}</p>
                <p className="proposal-mission"><strong>Mission</strong> {proposal.mission}</p>
                <label className="proposal-review-note">Decision note (optional)<textarea rows="3" maxLength="2000" value={proposalDecisionNotes[proposal.id] || ''} onChange={(event) => setProposalDecisionNotes((current) => ({ ...current, [proposal.id]: event.target.value }))} /></label>
                <div className="proposal-card-actions">
                  <button className="approve-button" disabled={loading} onClick={() => decideProposal(proposal.id, 'approved')}>Approve &amp; create organization</button>
                  <button className="decline-button" disabled={loading} onClick={() => decideProposal(proposal.id, 'declined')}>Decline proposal</button>
                </div>
              </article>
            ))}</div> : <EmptyState title="No proposals awaiting review" description="Submitted organization proposals will appear here." />}
          </section>
        )}

        {activePage === 'My Organizations' && (
          <section className="content-panel">
            <div className="section-heading"><div><span className="eyebrow">YOUR COMMUNITIES</span><h2>My organizations</h2><p>Organizations you belong to and your roles.</p></div></div>
            {myOrganizations.length ? <div className="organization-grid">{myOrganizations.map((membership) => (
              <article className={`org-card ${collegeTheme(membership.college)}`} key={membership.id}><div className="org-card-top"><CollegeLogo college={membership.college} organizationName={membership.name} className="org-avatar" /><span className="role-pill">{membership.role}</span></div><span className="category">{membership.category}</span><h3>{membership.name}</h3><p className="org-cause">{membership.college}</p><button className="outline-button" onClick={() => openOrganization(membership.id)}>Open organization <Icon name="arrowRight" /></button></article>
            ))}</div> : <EmptyState title="You haven't joined an organization yet" description="Send a request to join an open organization to get started." action="Discover organizations" onAction={() => navTo('Discover')} />}
          </section>
        )}

        {activePage === 'Dues' && (
          <section className="content-panel dues-page">
            <div className="section-heading dues-page-heading">
              <div><span className="eyebrow">MEMBERSHIP FINANCES</span><h2>Payments</h2><p>Track dues, payment history, and upcoming deadlines.</p></div>
              <div className="dues-filters">
                <label>School year
                  <select aria-label="Filter dues by school year" value={duesYearFilter} onChange={(event) => setDuesYearFilter(event.target.value)}>
                    <option value="all">All school years</option>
                    {duesYears.map((year) => <option value={year} key={year}>{year}</option>)}
                  </select>
                </label>
                <label>Payment status
                  <select aria-label="Filter dues by payment status" value={duesStatusFilter} onChange={(event) => setDuesStatusFilter(event.target.value)}>
                    <option value="all">All statuses</option>
                    <option value="paid">Paid</option>
                    <option value="unpaid">Unpaid (including partial)</option>
                    <option value="partial">Partial</option>
                    <option value="overdue">Overdue</option>
                  </select>
                </label>
              </div>
            </div>

            {duesReminders.length > 0 && <div className="dues-alert-list" role="status">
              {duesReminders.map((due) => {
                const reminder = duesReminder(due)
                return <div className={`dues-alert ${reminder}`} key={due.id}>
                  <Icon name={reminder === 'overdue' ? 'announcement' : 'calendar'} />
                  <div><strong>{reminder === 'overdue' ? 'Dues are overdue' : 'Dues are due soon'} · {due.organization.name}</strong><span>{due.school_year} · {formatCurrency(due.balance)} remaining · Due {formatDate(due.due_date)}</span></div>
                </div>
              })}
            </div>}

            <section className="dues-section">
              <div className="dues-section-heading"><div><h3>Your dues table</h3><p>Amounts paid and remaining for each organization and school year.</p></div><span className="member-total">{filteredMyDues.length} dues records</span></div>
              {filteredMyDues.length ? <div className="dues-table-wrap">
                <table className="dues-table">
                  <thead><tr><th>Organization</th><th>School year</th><th>Amount due</th><th>Paid</th><th>Balance</th><th>Due date</th><th>Status</th><th>Payment</th></tr></thead>
                  <tbody>{filteredMyDues.map((due) => (
                    <tr key={due.id}>
                      <td><strong>{due.organization.name}</strong><span>{due.organization.college}</span></td>
                      <td>{due.school_year}</td>
                      <td>{formatCurrency(due.amount)}</td>
                      <td>{formatCurrency(due.paid_amount)}</td>
                      <td>{formatCurrency(due.balance)}</td>
                      <td>{formatDate(due.due_date)}</td>
                      <td>
                        <span className={`dues-status status-${due.status}`}>{due.status}</span>
                        {Number(due.pending_amount) > 0 && <span className="payment-review-hint">{formatCurrency(due.pending_amount)} pending review</span>}
                        {due.payments?.filter((payment) => payment.status === 'rejected' && payment.review_note).map((payment) => (
                          <span className="payment-review-hint payment-rejection-note" key={payment.id}>Not approved: {payment.review_note}</span>
                        ))}
                      </td>
                      <td><div className="member-dues-payment-actions">
                        {Number(due.balance) - Number(due.pending_amount || 0) > 0
                          ? due.organization.gcash_number || due.organization.gcash_qr_url
                            ? <button className="outline-button payment-submit-button" type="button" onClick={() => openMemberPayment(due)}>Pay via GCash</button>
                            : <span className="payment-review-hint">GCash details not set</span>
                          : Number(due.pending_amount) > 0
                            ? <span className="payment-review-hint">Awaiting review</span>
                            : <span className="dues-paid-note">Settled</span>}
                        {due.payments?.filter((payment) => payment.status === 'pending').map((payment) => (
                          <button
                            className="text-button edit-payment-submission"
                            type="button"
                            key={payment.id}
                            onClick={() => openMemberPayment(due, payment)}
                          >
                            Edit pending submission
                          </button>
                        ))}
                      </div></td>
                    </tr>
                  ))}</tbody>
                </table>
              </div> : <EmptyState title="No dues records yet" description={myOrganizations.length ? 'Your organization treasurer will add dues for a school year.' : 'Join an organization to view its dues.'} />}
            </section>

            {duesManagerOrganizations.length > 0 && <section className="dues-section treasurer-dues">
              <div className="dues-section-heading"><div><span className="eyebrow">DUES MANAGEMENT</span><h3>Manage organization dues</h3><p>Treasurers, organization presidents, and admins can set annual dues, record payments, and review outstanding balances.</p></div></div>
              <label className="dues-organization-picker">Organization
                <select aria-label="Select organization to manage dues" value={selectedDuesOrganizationId} onChange={(event) => changeDuesOrganization(event.target.value)}>
                  <option value="">Select an organization</option>
                  {duesManagerOrganizations.map((organization) => <option key={organization.id} value={organization.id}>{organization.name}</option>)}
                </select>
              </label>
              {selectedDuesOrganization && <form className="dues-schedule-form" onSubmit={saveDuesSchedule}>
                <div><span className="eyebrow">ANNUAL SCHEDULE</span><h4>Set school-year dues</h4><p>Existing school years can be updated; recorded payments are protected from being reduced below the amount already collected.</p></div>
                <label>School year
                  <input aria-label="School year" inputMode="numeric" pattern="\d{4}-\d{4}" placeholder="2026-2027" value={duesSchoolYear} onChange={(event) => setDuesSchoolYear(event.target.value)} required />
                </label>
                <label>Amount (PHP)
                  <input aria-label="Dues amount in Philippine pesos" type="number" min="0.01" max="99999999.99" step="0.01" placeholder="500.00" value={duesAmount} onChange={(event) => setDuesAmount(event.target.value)} required />
                </label>
                <label>Due date
                  <input aria-label="Dues due date" type="date" value={duesDueDate} onChange={(event) => setDuesDueDate(event.target.value)} required />
                </label>
                <button className="primary-button" disabled={loading || !duesSchoolYear.trim() || !duesAmount || !duesDueDate}>Save dues schedule</button>
              </form>}
              {selectedDuesOrganization && <form className="gcash-settings-form" onSubmit={saveGcashDetails}>
                <div>
                  <span className="eyebrow">GCASH PAYMENT DETAILS</span>
                  <h4>How members can pay</h4>
                  <p>Members can submit their GCash payment proof here. Submissions stay pending until reviewed.</p>
                </div>
                <label>GCash mobile number
                  <input
                    type="tel"
                    inputMode="tel"
                    pattern="(09[0-9]{9}|\\+639[0-9]{9})"
                    placeholder="09XXXXXXXXX or +639XXXXXXXXX"
                    value={duesGcashNumber}
                    onChange={(event) => setDuesGcashNumber(event.target.value)}
                  />
                </label>
                <label>Upload or replace QR code
                  <input type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => setDuesGcashQrFile(event.target.files?.[0] || null)} />
                </label>
                {selectedDuesOrganization.organization.gcash_qr_url && !duesGcashQrFile && <img className="gcash-current-qr" src={selectedDuesOrganization.organization.gcash_qr_url} alt={`${selectedDuesOrganization.organization.name} GCash QR code`} />}
                <button className="primary-button" disabled={loading || (!duesGcashNumber.trim() && !duesGcashQrFile && !selectedDuesOrganization.organization.gcash_qr_url)}>Save GCash details</button>
              </form>}
              {selectedDuesOrganization && <div className="dues-management-list">
                <div className="dues-section-heading"><div><h4>Payment roster</h4><p>Record member payments and print a stub for every transaction.</p></div></div>
                {filteredManagerDues.length ? filteredManagerDues.map((due) => {
                  const visibleMembers = due.members.filter((member) => matchesDuesStatus(member.status))
                  const outstandingCount = due.members.filter((member) => Number(member.balance) > 0).length
                  return <article className="dues-year-card" key={due.id}>
                    <header><div><h4>{due.school_year}</h4><span>{formatCurrency(due.amount)} per member · Due {formatDate(due.due_date)}</span></div><div className="dues-year-summary"><strong>{outstandingCount} unpaid</strong><span>{formatCurrency(due.total_collected)} collected</span><button className="text-button" type="button" onClick={() => editDuesSchedule(due)}>Edit schedule</button></div></header>
                    {visibleMembers.length ? <div className="dues-table-wrap">
                      <table className="dues-table dues-roster-table">
                        <thead><tr><th>Member</th><th>Amount</th><th>Paid</th><th>Balance</th><th>Status</th><th>Record payment</th></tr></thead>
                        <tbody>{visibleMembers.map((member) => {
                          const pendingAmount = (member.payments || [])
                            .filter((payment) => payment.status === 'pending')
                            .reduce((sum, payment) => sum + Number(payment.amount), 0)
                          const availableBalance = Math.max(0, Number(member.balance) - pendingAmount)
                          return <tr key={member.user_id}>
                            <td>
                              <button
                                type="button"
                                className="payment-member-button"
                                onClick={() => openDuesMemberPayments(selectedDuesOrganization.organization.id, due, member)}
                              >
                                {member.name}
                              </button>
                              <span>{member.email}</span>
                            </td>
                            <td>{formatCurrency(member.amount)}</td>
                            <td>{formatCurrency(member.paid_amount)}</td>
                            <td>{formatCurrency(member.balance)}</td>
                            <td><span className={`dues-status status-${member.status}`}>{member.status}</span>{pendingAmount > 0 && <span className="payment-review-hint">{formatCurrency(pendingAmount)} pending review</span>}</td>
                            <td>{availableBalance > 0
                              ? <form className="dues-payment-form" onSubmit={(event) => {
                                event.preventDefault()
                                recordDuesPayment(selectedDuesOrganization.organization.id, due.id, member.user_id, event.currentTarget.amount.value)
                              }}>
                                <input name="amount" type="number" aria-label={`Payment amount for ${member.name}`} min="0.01" max={availableBalance} step="0.01" defaultValue={availableBalance} required />
                                <button className="approve-button" disabled={loading}>Record</button>
                              </form>
                              : pendingAmount > 0 ? <span className="payment-review-hint">Awaiting review</span> : <span className="dues-paid-note">Fully paid</span>}
                            </td>
                          </tr>
                        })}</tbody>
                      </table>
                    </div> : <EmptyState title="No members match these filters" description="Change the school year or payment-status filters to see the roster." />}
                  </article>
                }) : <EmptyState title="No dues schedule for this filter" description="Save an annual schedule above or choose another school year or payment status." />}
              </div>}
            </section>}
          </section>
        )}

        {activePage === 'Officer Requests' && (
          <section className="content-panel">
            <div className="section-heading"><div><span className="eyebrow">OFFICER TOOLS</span><h2>Join requests and members</h2><p>Review requests and manage membership for organizations you lead.</p></div><select aria-label="Filter officer tools by organization" value={officerOrganizationId} onChange={(event) => changeOfficerOrganization(event.target.value)}><option value="all">All my organizations</option>{officerOrganizations.map((organization) => <option key={organization.id} value={organization.id}>{organization.name}</option>)}</select></div>
            {officerRequests.length ? <div className="request-list">{officerRequests.map((request) => (
              <article className="request-row" key={request.id}>
                <div className="request-person">
                  <UserAvatar name={request.user.name} src={request.user.profile_picture_url} size="compact" />
                  <div><h3>{request.user.name}</h3><p>{request.user.email} · {request.organization.name}</p></div>
                </div>
                <span className={`status-label status-${request.status}`}>{request.status}</span>
                {request.status === 'pending' && <div className="decision-buttons"><button className="approve-button" disabled={loading} onClick={() => decideRequest(request.id, 'approved')}>Approve</button><button className="decline-button" disabled={loading} onClick={() => decideRequest(request.id, 'declined')}>Decline</button></div>}
              </article>
            ))}</div> : <EmptyState title="No requests to review" description="Pending join requests for your organizations will appear here." />}
            <section className="officer-members">
              <div className="officer-section-heading">
                <div><h3>Manage members</h3><p>Members are grouped by organization and role.</p></div>
                <span className="member-total">{officerMembers.length} members</span>
              </div>
              {officerMembers.length ? <div className="officer-member-groups">{officerOrganizations
                .filter((organization) => officerMembers.some((member) => member.organization.id === organization.id))
                .map((organization) => {
                  const organizationMembers = officerMembers.filter(
                    (member) => member.organization.id === organization.id,
                  )
                  const leadership = organizationMembers.filter((member) => member.role !== 'Member')
                  const regularMembers = organizationMembers.filter((member) => member.role === 'Member')
                  const isPresident = officerOrganizations.some(
                    (membership) => membership.id === organization.id,
                  )

                  return (
                    <article className="officer-organization-group" key={organization.id}>
                      <header className="officer-organization-heading">
                        <div><h4>{organization.name}</h4><span>{organizationMembers.length} members</span></div>
                      </header>
                      {leadership.length > 0 && <section className="officer-role-group">
                        <h5>Leadership team <span>{leadership.length}</span></h5>
                        <div className="officer-roster">{leadership.map((member) => (
                          <div className="officer-member-row" key={member.id}>
                            <div className="officer-member-identity">
                              <UserAvatar name={member.name} src={member.profile_picture_url} size="member" />
                              <div><strong>{member.name} ({member.role})</strong><span>{member.email}</span></div>
                            </div>
                            <div className="decision-buttons">
                              {isPresident && member.role !== 'President' && member.user_id !== user.id
                                ? <>
                                  <select
                                    className="officer-role-select"
                                    aria-label={`Change role for ${member.name}`}
                                    disabled={loading}
                                    value={officerRoleOptions.includes(member.role) ? member.role : ''}
                                    onChange={(event) => assignOrganizationRole(organization.id, member.id, event.target.value)}
                                  >
                                    <option value="">Assign officer role</option>
                                    {officerRoleOptions.map((role) => <option key={role} value={role}>{role}</option>)}
                                  </select>
                                  <button className="decline-button" disabled={loading} onClick={() => removeOrganizationMember(organization.id, member.id)}>Remove</button>
                                </>
                                : <span className="member-row-spacer" />}
                            </div>
                          </div>
                        ))}</div>
                      </section>}
                      {regularMembers.length > 0 && <section className="officer-role-group">
                        <h5>Members <span>{regularMembers.length}</span></h5>
                        <div className="officer-roster">{regularMembers.map((member) => (
                          <div className="officer-member-row" key={member.id}>
                            <div className="officer-member-identity">
                              <UserAvatar name={member.name} src={member.profile_picture_url} size="member" />
                              <div><strong>{member.name} ({member.role})</strong><span>{member.email}</span></div>
                            </div>
                            <div className="decision-buttons">
                              {isPresident && member.user_id !== user.id && <>
                                <select
                                  className="officer-role-select"
                                  aria-label={`Change role for ${member.name}`}
                                  disabled={loading}
                                  value={officerRoleOptions.includes(member.role) ? member.role : ''}
                                  onChange={(event) => assignOrganizationRole(organization.id, member.id, event.target.value)}
                                >
                                  <option value="">Assign officer role</option>
                                  {officerRoleOptions.map((role) => <option key={role} value={role}>{role}</option>)}
                                </select>
                                <button className="decline-button" disabled={loading} onClick={() => removeOrganizationMember(organization.id, member.id)}>Remove</button>
                              </>}
                            </div>
                          </div>
                        ))}</div>
                      </section>}
                    </article>
                  )
                })}</div> : <EmptyState title="No members found" description="Members of your organizations will appear here." />}
            </section>
            <section className="officer-members">
              <h3>Event attendance</h3>
              {officerEvents.length ? <div className="request-list">{officerEvents.map((event) => (
                  <article className="request-row event-management-row" key={event.id}>
                    <div><h3>{event.title}</h3><p>{event.organization.name} · {new Date(event.starts_at).toLocaleString()} · {event.attendance_count} attended · {event.rsvp_count} RSVPs</p></div>
                    <div className="decision-buttons">
                      {!event.is_cancelled && <button className="outline-button attendance-action" onClick={() => openEventAttendance(event.id)}>{String(event.id) === selectedEventId ? 'Refresh roster' : 'Mark attendance'}</button>}
                      {!event.is_cancelled && !event.is_past && <button className="decline-button" disabled={loading} onClick={() => cancelOrganizationEvent(event.organization_id, event.id)}>Cancel event</button>}
                      {event.is_cancelled && <span className="event-cancelled-label">Cancelled</span>}
                    </div>
                  </article>
                ))}</div> : <EmptyState title="No events to manage" description="Your organizations have no scheduled events." />}
              {selectedEventId && <div className="member-list attendance-roster">{eventAttendance.map((attendee) => (
                <div className="member-row" key={attendee.user_id}>
                  <div className="request-person attendance-person">
                    <UserAvatar name={attendee.name} src={attendee.profile_picture_url} size="compact" />
                    <div className="attendance-person-details">
                      <strong>{attendee.name}</strong>
                      <span className="attendance-email">{attendee.email}</span>
                      <span className="attendance-role">{attendee.role}{attendee.rsvped ? ' · RSVP’d' : ''}</span>
                    </div>
                  </div>
                  {attendee.attended
                    ? <span className="status-label status-approved">Attended</span>
                    : <div className="decision-buttons"><button className="approve-button" disabled={loading} onClick={() => markAttendance(selectedEventId, attendee.user_id)}>Mark attended</button></div>}
                </div>
              ))}</div>}
            </section>
          </section>
        )}

        {memberPaymentDue && <div
          className="payment-history-overlay"
          role="presentation"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget) setMemberPaymentDue(null)
          }}
        >
          <section className="payment-history-dialog member-gcash-dialog" role="dialog" aria-modal="true" aria-labelledby="member-gcash-title">
            <header className="payment-history-heading">
              <div>
                <span className="eyebrow">{editingMemberPayment ? 'EDIT PAYMENT SUBMISSION' : 'GCASH PAYMENT'}</span>
                <h2 id="member-gcash-title">{memberPaymentDue.organization.name}</h2>
                <p>School year {memberPaymentDue.school_year} · Balance {formatCurrency(memberPaymentDue.balance)}</p>
              </div>
              <button type="button" className="text-button" onClick={() => setMemberPaymentDue(null)}>Close</button>
            </header>
            <div className="gcash-payment-destination">
              {memberPaymentDue.organization.gcash_qr_url && <img src={memberPaymentDue.organization.gcash_qr_url} alt={`${memberPaymentDue.organization.name} GCash QR code`} />}
              {memberPaymentDue.organization.gcash_number && <div><span>GCash number</span><strong>{memberPaymentDue.organization.gcash_number}</strong></div>}
              <p>{editingMemberPayment
                ? 'Correct the amount or reference digits, or upload a replacement receipt. This submission remains pending until reviewed.'
                : 'Send your payment using the QR code or GCash number, then submit the details below. Your dues status updates after review.'}</p>
            </div>
            <form className="member-payment-form" onSubmit={submitMemberDuesPayment}>
              <label>Amount paid (PHP)
                <input
                  type="number"
                  min="0.01"
                  max={Number(memberPaymentDue.balance) - Number(memberPaymentDue.pending_amount || 0) + Number(editingMemberPayment?.amount || 0)}
                  step="0.01"
                  value={memberPaymentAmount}
                  onChange={(event) => setMemberPaymentAmount(event.target.value)}
                  required
                />
              </label>
              <label>Last four digits of reference number (optional)
                <input
                  type="text"
                  inputMode="numeric"
                  pattern="[0-9]{4}"
                  maxLength="4"
                  value={memberPaymentReference}
                  onChange={(event) => setMemberPaymentReference(event.target.value.replace(/\D/g, '').slice(0, 4))}
                  placeholder="1234"
                />
              </label>
              <label>Proof of payment (optional)
                <input type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => setMemberPaymentProof(event.target.files?.[0] || null)} />
                <span>{editingMemberPayment?.has_proof
                  ? 'A receipt is already attached. Select an image only if you want to replace it. Maximum 5 MB.'
                  : 'Upload an image, enter the last 4 reference digits, or provide both. Maximum 5 MB.'}</span>
              </label>
              <div className="member-payment-form-actions">
                <button className="outline-button" type="button" onClick={() => { setMemberPaymentDue(null); setEditingMemberPayment(null) }}>Cancel</button>
                <button className="primary-button" type="submit" disabled={loading || (!memberPaymentProof && memberPaymentReference.length !== 4 && !editingMemberPayment?.has_proof) || !memberPaymentAmount}>{editingMemberPayment ? 'Save changes' : 'Submit for review'}</button>
              </div>
            </form>
          </section>
        </div>}

        {selectedDuesMember && <div
          className="payment-history-overlay"
          role="presentation"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget) closeDuesMemberDialog()
          }}
        >
          <section className="payment-history-dialog" role="dialog" aria-modal="true" aria-labelledby="payment-history-title">
            <header className="payment-history-heading">
              <div>
                <span className="eyebrow">MEMBER ACCOUNT</span>
                <h2 id="payment-history-title">{selectedDuesMember.member.name}</h2>
                <p>{selectedDuesMember.member.email} · {selectedDuesMember.due.school_year}</p>
              </div>
              <button
                type="button"
                className="text-button"
                aria-label="Close payment history"
                onClick={closeDuesMemberDialog}
              >Close</button>
            </header>
            <div className="payment-history-summary">
              <div><span>Annual dues</span><strong>{formatCurrency(selectedDuesMember.due.amount)}</strong></div>
              <div><span>Paid to date</span><strong>{formatCurrency(selectedDuesMember.member.paid_amount)}</strong></div>
              <div><span>Outstanding balance</span><strong>{formatCurrency(selectedDuesMember.member.balance)}</strong></div>
            </div>
            <div className="payment-history-section">
              <div className="dues-section-heading">
                <div><h3>Payment history</h3><p>Recorded transactions for this member and school year.</p></div>
                <span className={`dues-status status-${selectedDuesMember.member.status}`}>{selectedDuesMember.member.status}</span>
              </div>
              {selectedDuesMember.member.payments?.length ? <div className="payment-history-list">
                {selectedDuesMember.member.payments.map((payment) => (
                  <article className="payment-history-item" key={payment.id}>
                    <div className="payment-history-item-heading">
                      <div><strong>{formatCurrency(payment.amount)}</strong><span>Receipt DUES-{String(payment.id).padStart(6, '0')}</span></div>
                      <span>{new Date(payment.paid_at).toLocaleString()}</span>
                    </div>
                    <div className="payment-history-item-details">
                      <div className="payment-history-review-meta">
                        <span className={`dues-status status-${payment.status}`}>{payment.status}</span>
                        {payment.reference_last_four && <span>Ref. ending {payment.reference_last_four}</span>}
                        {payment.status === 'pending' && payment.has_proof && <button className="text-button" type="button" disabled={loading} onClick={() => viewGcashProof(payment)}>View proof</button>}
                        {payment.reviewed_by && <span>Reviewed by {payment.reviewed_by}</span>}
                        {payment.review_note && <span>{payment.review_note}</span>}
                        {paymentProofPreview && paymentProofPreviewId === payment.id && <img className="payment-proof-preview" src={paymentProofPreview} alt={`Payment proof for ${selectedDuesMember.member.name}`} />}
                      </div>
                      {payment.status === 'pending' && Number(selectedDuesMember.member.user_id) !== Number(user.id)
                        ? <div className="payment-review-actions">
                          <button className="approve-button" type="button" disabled={loading} onClick={() => reviewGcashPayment(payment, 'approved')}>Approve</button>
                          <button className="decline-button" type="button" disabled={loading} onClick={() => reviewGcashPayment(payment, 'rejected')}>Reject</button>
                        </div>
                        : payment.status === 'pending'
                          ? <span className="payment-review-hint">Awaiting another reviewer</span>
                        : editingDuesPaymentId === payment.id
                        ? <form className="payment-correction-form" onSubmit={correctDuesPayment}>
                          <label>
                            Correct payment amount
                            <input
                              aria-label={`Correct payment amount for receipt DUES-${String(payment.id).padStart(6, '0')}`}
                              type="number"
                              min="0.01"
                              max={Number(selectedDuesMember.due.amount) - (Number(selectedDuesMember.member.paid_amount) - Number(payment.amount))}
                              step="0.01"
                              value={correctedDuesPaymentAmount}
                              onChange={(event) => setCorrectedDuesPaymentAmount(event.target.value)}
                              required
                            />
                          </label>
                          <div>
                            <button className="outline-button" type="button" disabled={loading} onClick={() => setEditingDuesPaymentId(null)}>Cancel</button>
                            <button className="primary-button" type="submit" disabled={loading || !correctedDuesPaymentAmount}>Save correction</button>
                          </div>
                        </form>
                        : payment.reviewed_by && ['approved', 'rejected'].includes(payment.status)
                          ? <div className="payment-history-action-links">
                            {Number(selectedDuesMember.member.user_id) !== Number(user.id) && <button className="text-button" type="button" disabled={loading} onClick={() => reviewGcashPayment(payment, 'pending')}>Cancel decision</button>}
                            {payment.status === 'approved' && <button className="text-button" type="button" disabled={loading} onClick={() => startCorrectingDuesPayment(payment)}>Edit amount</button>}
                          </div>
                          : payment.status === 'approved' && <button className="text-button" type="button" disabled={loading} onClick={() => startCorrectingDuesPayment(payment)}>Edit amount</button>}
                    </div>
                  </article>
                ))}
              </div> : <EmptyState title="No payments recorded" description="This member has not made a payment for this school year." />}
            </div>
          </section>
        </div>}

        {duesReceipt && <div className="payment-receipt-overlay" role="presentation" onMouseDown={(event) => {
          if (event.target === event.currentTarget) setDuesReceipt(null)
        }}>
          <section className="payment-receipt-dialog" role="dialog" aria-modal="true" aria-labelledby="payment-receipt-title">
            <div className="payment-receipt-print">
              <div className="receipt-brand"><span className="brand-icon" aria-hidden="true"><span /></span><div><strong>OrgLife</strong><span>Official payment stub</span></div></div>
              <div className="receipt-heading"><span className="eyebrow">PAYMENT RECEIPT</span><h2 id="payment-receipt-title">{duesReceipt.organization}</h2><p>{duesReceipt.college} · School year {duesReceipt.school_year}</p></div>
              <div className="receipt-number"><span>Receipt no.</span><strong>{duesReceipt.receipt_number}</strong></div>
              <dl className="receipt-details">
                <div><dt>Received from</dt><dd>{duesReceipt.member_name}</dd></div>
                <div><dt>Email</dt><dd>{duesReceipt.member_email}</dd></div>
                <div><dt>Payment date</dt><dd>{new Date(duesReceipt.paid_at).toLocaleString()}</dd></div>
                <div><dt>Recorded by</dt><dd>{duesReceipt.recorded_by || 'Treasurer'}</dd></div>
                <div><dt>Annual dues</dt><dd>{formatCurrency(duesReceipt.dues_amount)}</dd></div>
                <div><dt>Amount received</dt><dd className="receipt-amount">{formatCurrency(duesReceipt.payment_amount)}</dd></div>
                <div><dt>Total paid this year</dt><dd>{formatCurrency(duesReceipt.total_paid)}</dd></div>
                <div><dt>Remaining balance</dt><dd>{formatCurrency(duesReceipt.balance)}</dd></div>
              </dl>
              <p className="receipt-thank-you">Thank you. Please keep this stub for your records.</p>
            </div>
            <div className="receipt-screen-actions">
              <button className="outline-button" onClick={() => setDuesReceipt(null)}>Close</button>
              <button className="primary-button" onClick={() => window.print()}>Print stub</button>
            </div>
          </section>
        </div>}

        <footer>OrgLife · Student Organization Management System</footer>
      </main>
    </div>
  )
}

function Stat({ icon, label, value, tone }) {
  return <div className="stat-card"><div className={`stat-icon ${tone}`}><Icon name={icon} /></div><p>{label}</p><h3>{String(value).padStart(2, '0')}</h3></div>
}

function EmptyState({ title, description, action, onAction }) {
  return <div className="empty-state"><h3>{title}</h3><p>{description}</p>{action && <button className="outline-button" onClick={onAction}>{action}</button>}</div>
}

function UserAvatar({ name, src, size = '' }) {
  const [imageFailed, setImageFailed] = useState(false)
  const initials = name?.trim().charAt(0).toUpperCase() || 'U'

  return (
    <span className={`profile-avatar ${size}`.trim()}>
      {src && !imageFailed
        ? <img src={src} alt={`${name || 'User'} profile`} onError={() => setImageFailed(true)} />
        : initials}
    </span>
  )
}

function OrganizationDetails({
  organization,
  members,
  loading,
  onBack,
  onJoin,
  onWithdraw,
  onViewMembers,
  onPostAnnouncement,
  announcementTitle,
  announcementBody,
  eventForm,
  editingEventId,
  onEventFormChange,
  onSaveEvent,
  onCancelEditEvent,
  onAnnouncementTitleChange,
  onAnnouncementBodyChange,
  onRsvp,
  onDeleteAnnouncement,
  onCancelEvent,
  onEditEvent,
  isAdmin = false,
}) {
  const isMember = Boolean(organization.membership_role)
  const isOfficer = isAdmin || organization.membership_role === 'President'
  const canRequest = organization.open && !isMember && organization.request_status !== 'pending'

  return (
    <section className={`content-panel organization-detail ${collegeTheme(organization.college)}`}>
      <button className="text-button back-button" onClick={onBack}><Icon name="arrowLeft" />Back to organizations</button>
      <div className="detail-heading">
        <CollegeLogo college={organization.college} organizationName={organization.name} className="org-avatar large-avatar" />
        <div><span className="category">{organization.category} · {organization.college}</span><h2>{organization.name}</h2><p>{organization.cause}</p></div>
        <span className={`membership ${organization.open ? 'open' : 'closed'}`}>{organization.open ? 'Open membership' : 'Membership closed'}</span>
      </div>
      <div className="detail-columns">
        <section className="detail-block"><h3>Our mission</h3><p>{organization.mission}</p></section>
        <section className="detail-block"><h3>Officers</h3>{organization.officers.length ? <ul className="plain-list">{organization.officers.map((officer) => <li key={`${officer.name}-${officer.role}`}><div className="officer-list-person"><UserAvatar name={officer.name} src={officer.profile_picture_url} size="compact" /><strong>{officer.name}</strong></div><span>{officer.role}</span></li>)}</ul> : <p>Officer information hasn't been added yet.</p>}</section>
      </div>
      <section className="detail-block">
        <h3>Organization calendar</h3>
        {isOfficer && <form className="event-management-form" onSubmit={onSaveEvent}>
          <div className="event-form-heading"><div><span className="eyebrow">{editingEventId ? 'EDIT EVENT' : 'EVENT MANAGEMENT'}</span><h4>{editingEventId ? 'Update this event' : 'Create an event'}</h4><p>Add a date, venue, and a seat limit for your organization members.</p></div></div>
          <div className="event-form-grid">
            <label>Event name<input name="title" value={eventForm.title} onChange={onEventFormChange} maxLength="255" required /></label>
            <label>Date and time<input name="starts_at" type="datetime-local" value={eventForm.starts_at} onChange={onEventFormChange} required /></label>
            <label>Venue<input name="location" value={eventForm.location} onChange={onEventFormChange} maxLength="255" required /></label>
            <label>Seat limit<input name="seat_limit" type="number" min="1" max="1000000" step="1" value={eventForm.seat_limit} onChange={onEventFormChange} required /></label>
            <label className="event-description-field">Description<textarea name="description" rows="3" maxLength="5000" value={eventForm.description} onChange={onEventFormChange} /></label>
          </div>
          <div className="event-form-actions">
            <button className="primary-button" disabled={loading || !eventForm.title.trim() || !eventForm.starts_at || !eventForm.location.trim() || !eventForm.seat_limit}>{editingEventId ? 'Save event changes' : 'Create event'}</button>
            {editingEventId && <button type="button" className="outline-button" disabled={loading} onClick={onCancelEditEvent}>Cancel editing</button>}
          </div>
        </form>}
        <EventList
          events={organization.events}
          organizationCollege={organization.college}
          isMember={isMember}
          loading={loading}
          onRsvp={onRsvp}
          onEditEvent={onEditEvent}
          onCancelEvent={onCancelEvent}
          canManageEvents={() => isOfficer}
        />
      </section>
      {(isMember || isAdmin) && <section className="detail-block">
        <h3>Organization announcements</h3>
        {organization.announcements?.length ? <AnnouncementList
          announcements={organization.announcements}
          onDelete={onDeleteAnnouncement}
          loading={loading}
          canDelete={() => isOfficer}
        /> : <p>No announcements have been posted yet.</p>}
        {isOfficer && <form className="announcement-form" onSubmit={(event) => { event.preventDefault(); onPostAnnouncement(announcementTitle, announcementBody) }}>
          <label>Title<input required maxLength="160" value={announcementTitle} onChange={(event) => onAnnouncementTitleChange(event.target.value)} /></label>
          <label>Announcement<textarea required maxLength="5000" rows="4" value={announcementBody} onChange={(event) => onAnnouncementBodyChange(event.target.value)} /></label>
          <button className="primary-button" disabled={loading || !announcementTitle.trim() || !announcementBody.trim()}>Post announcement</button>
        </form>}
      </section>}
      <div className="detail-actions">
        {canRequest && <button className="primary-button" disabled={loading} onClick={onJoin}>{organization.request_status === 'declined' || organization.request_status === 'withdrawn' ? 'Request to join again' : 'Request to join'}</button>}
        {organization.request_status === 'pending' && <><span className="status-label status-pending">Request pending</span><button className="text-button danger-text" disabled={loading} onClick={onWithdraw}>Withdraw request</button></>}
        {organization.request_status === 'declined' && <span className="status-label status-declined">Request declined</span>}
        {isMember && <span className="role-pill">Member · {organization.membership_role}</span>}
        {(isMember || isAdmin) && <button className="outline-button members-toggle" disabled={loading} onClick={onViewMembers}>{members.length ? 'Refresh member list' : 'View member list'}</button>}
      </div>
      {members.length > 0 && <section className="detail-block"><h3>Members and roles ({members.length})</h3><div className="member-list">{members.map((member) => <div className="member-row" key={member.email}><div className="request-person member-identity"><UserAvatar name={member.name} src={member.profile_picture_url} size="compact" /><div className="member-identity-details"><strong>{member.name}</strong><span>{member.email}</span></div></div><span className="role-pill">{member.role}</span></div>)}</div></section>}
    </section>
  )
}

function EventList({ events, isMember = false, loading = false, onRsvp, onOpenOrganization, organizationCollege, onEditEvent, onCancelEvent, canManageEvents }) {
  if (!events.length) {
    return <p>No events are scheduled yet.</p>
  }

  const sections = [
    { title: 'Upcoming events', events: events.filter((event) => !event.is_past) },
    { title: 'Past events', events: events.filter((event) => event.is_past) },
  ].filter((section) => section.events.length)

  return <div className="event-calendar">
    {sections.map((section) => (
      <section className="calendar-section" key={section.title}>
        <h4>{section.title}</h4>
        <div className="event-list">{section.events.map((event) => (
          <article className={`event-card ${collegeTheme(event.organization?.college || organizationCollege)}`} key={event.id}>
            <strong>{event.title}</strong>
            <time>{new Date(event.starts_at).toLocaleString()}</time>
            {event.is_cancelled && <span className="event-cancelled-label">Cancelled</span>}
            {event.organization && <span className="event-organization"><CollegeLogo college={event.organization.college} organizationName={event.organization.name} className="college-logo-small" />{event.organization.name}</span>}
            {event.location && <span>{event.location}</span>}
            <span className="event-seat-count">{event.rsvp_count}{event.seat_limit ? ` / ${event.seat_limit}` : ''} seats reserved</span>
            {event.description && <p>{event.description}</p>}
            <div className="event-counts">
              <span>{event.rsvp_count} going</span>
              <span>{event.attendance_count} attended</span>
            </div>
            {isMember && !event.is_past && !event.is_cancelled && onRsvp && (
              <button
                className={event.rsvped ? 'decline-button' : 'approve-button'}
                disabled={loading || (!event.rsvped && event.seat_limit !== null && event.seat_limit !== undefined && event.rsvp_count >= event.seat_limit)}
                onClick={() => onRsvp(event.id, event.rsvped)}
              >
                {event.rsvped ? 'Cancel RSVP' : (event.seat_limit !== null && event.seat_limit !== undefined && event.rsvp_count >= event.seat_limit ? 'Full' : 'RSVP')}
              </button>
            )}
            {onOpenOrganization && event.organization && (
              <button className="text-button" onClick={() => onOpenOrganization(event.organization.id)}>
                Open organization <Icon name="arrowRight" />
              </button>
            )}
            {canManageEvents?.(event) && !event.is_past && !event.is_cancelled && <div className="event-management-actions">
              <button className="outline-button" disabled={loading} onClick={() => onEditEvent?.(event)}>Edit event</button>
              <button className="outline-button" disabled={loading} onClick={() => onCancelEvent?.(event.organization_id, event.id)}>Cancel event</button>
            </div>}
          </article>
        ))}</div>
      </section>
    ))}
  </div>
}

function AnnouncementList({ announcements, onOpenOrganization, onDelete, canDelete, loading = false }) {
  if (!announcements.length) {
    return <EmptyState title="No announcements yet" description="Posts from your organizations will appear here." />
  }

  return <div className="announcement-list">{announcements.map((announcement) => (
    <article className={`announcement-card ${collegeTheme(announcement.organization.college)}`} key={announcement.id}>
      <div className="announcement-meta">
        <span className="announcement-organization"><CollegeLogo college={announcement.organization.college} organizationName={announcement.organization.name} className="college-logo-small" /><strong>{announcement.organization.name}</strong></span>
        <time>{new Date(announcement.created_at).toLocaleString()}</time>
      </div>
      <h3>{announcement.title}</h3>
      <p>{announcement.body}</p>
      <div className="announcement-author"><UserAvatar name={announcement.author.name} src={announcement.author.profile_picture_url} size="tiny" /><span>Posted by {announcement.author.name}</span></div>
      {onOpenOrganization && <button className="text-button" onClick={() => onOpenOrganization(announcement.organization.id)}>Open organization <Icon name="arrowRight" /></button>}
      {onDelete && canDelete?.(announcement) && (
        <button className="decline-button" disabled={loading} onClick={() => onDelete(announcement.organization.id, announcement.id)}>
          Delete announcement
        </button>
      )}
    </article>
  ))}</div>
}

export default App
